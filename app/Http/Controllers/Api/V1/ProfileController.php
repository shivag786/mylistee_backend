<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerPerson;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in customer's own details (mobile, birthday, gender,
 * anniversary) and the people they celebrate. Favourite shops use the dates
 * for birthday and anniversary offers.
 */
class ProfileController extends Controller
{
    /** Most people one customer can save. */
    public const MAX_PEOPLE = 10;

    private const GENDERS = ['male', 'female', 'other'];

    /** GET /profile */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->payload($request->user()), 'Profile.');
    }

    /** PUT /profile */
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            // A mobile can be added once; after that it is how the account
            // signs in, so it is not changed here.
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^[6-9]\d{9}$/', Rule::unique('users', 'phone')->ignore($user->id)],
            'dob' => ['sometimes', 'nullable', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['sometimes', 'nullable', Rule::in(self::GENDERS)],
            'anniversary' => ['sometimes', 'nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
        ], [
            'phone.regex' => 'Enter a valid 10-digit mobile number.',
            'phone.unique' => 'This mobile number is already on another account.',
        ]);

        if (array_key_exists('phone', $data)) {
            if ($user->phone !== null && $user->phone !== '' && $data['phone'] !== $user->phone) {
                throw ValidationException::withMessages(['phone' => ['Your mobile number can’t be changed here.']]);
            }
            if ($data['phone'] === null) {
                unset($data['phone']);
            }
        }

        $user->fill($data)->save();

        return ApiResponse::success($this->payload($user->fresh()), 'Profile saved.');
    }

    /** POST /profile/people */
    public function storePerson(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->people()->count() >= self::MAX_PEOPLE) {
            throw ValidationException::withMessages(['name' => ['You can save up to '.self::MAX_PEOPLE.' people.']]);
        }

        $person = $user->people()->create($this->personData($request));

        return ApiResponse::success($person->payload(), "{$person->name} saved.", status: 201);
    }

    /** PUT /profile/people/{uuid} */
    public function updatePerson(Request $request, string $uuid): JsonResponse
    {
        $person = $request->user()->people()->where('uuid', $uuid)->firstOrFail();
        $data = $this->personData($request);
        // A changed date may need its reminder again this year.
        if (($data['dob'] ?? null) !== $person->dob?->toDateString()) {
            $data['dob_reminded_year'] = null;
        }
        if (($data['anniversary'] ?? null) !== $person->anniversary?->toDateString()) {
            $data['anniversary_reminded_year'] = null;
        }
        $person->update($data);

        return ApiResponse::success($person->fresh()->payload(), 'Saved.');
    }

    /** DELETE /profile/people/{uuid} */
    public function destroyPerson(Request $request, string $uuid): JsonResponse
    {
        $request->user()->people()->where('uuid', $uuid)->firstOrFail()->delete();

        return ApiResponse::success(null, 'Removed.');
    }

    /** @return array<string, mixed> */
    private function personData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'relation' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', Rule::in(self::GENDERS)],
            'dob' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'anniversary' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
        ]);
        if (empty($data['dob']) && empty($data['anniversary'])) {
            throw ValidationException::withMessages(['dob' => ['Add a birthday or an anniversary.']]);
        }

        return [
            'name' => trim($data['name']),
            'relation' => isset($data['relation']) && trim($data['relation']) !== '' ? trim($data['relation']) : null,
            'gender' => $data['gender'] ?? null,
            'dob' => $data['dob'] ?? null,
            'anniversary' => $data['anniversary'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            // Once set, the mobile is how the account signs in.
            'phoneLocked' => $user->phone !== null && $user->phone !== '',
            'dob' => $user->dob?->toDateString(),
            'gender' => $user->gender,
            'anniversary' => $user->anniversary?->toDateString(),
            'people' => $user->people()->orderBy('name')->get()->map->payload()->all(),
            'maxPeople' => self::MAX_PEOPLE,
        ];
    }
}
