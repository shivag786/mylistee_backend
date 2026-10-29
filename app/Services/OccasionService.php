<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\CustomerPerson;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Birthdays and anniversaries: the customer's own and those of the people
 * they saved. A couple of days ahead the customer is reminded, with what
 * their favourite shops are offering; shops can also pick these customers as
 * a "Birthdays & anniversaries" audience when they send an offer.
 */
class OccasionService
{
    /** Days ahead the customer is reminded. */
    public const REMIND_DAYS_AHEAD = 2;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly RewardService $rewards,
    ) {}

    /**
     * Limit `$query` to rows whose `$column` falls on one of `$days` (any
     * year). Someone born on 29 Feb celebrates on the 28th in other years.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<Carbon>  $days
     */
    public function onDays(Builder $query, string $column, array $days): Builder
    {
        return $query->where(function (Builder $q) use ($column, $days): void {
            foreach ($days as $day) {
                $q->orWhere(fn (Builder $w) => $w->whereMonth($column, $day->month)->whereDay($column, $day->day));
                if ($day->month === 2 && $day->day === 28 && ! $day->isLeapYear()) {
                    $q->orWhere(fn (Builder $w) => $w->whereMonth($column, 2)->whereDay($column, 29));
                }
            }
        });
    }

    /**
     * Customers with an occasion of their own or of a saved person in the
     * next `$days` days (today included) — the shop audience.
     *
     * @return list<int>
     */
    public function customerIdsWithOccasionSoon(int $days = 7): array
    {
        $window = [];
        $today = Carbon::now(config('app.business_timezone'))->startOfDay();
        for ($i = 0; $i < $days; $i++) {
            $window[] = $today->copy()->addDays($i);
        }

        $own = User::query()->where(fn (Builder $q) => $q
            ->where(fn (Builder $w) => $this->onDays($w->whereNotNull('dob'), 'dob', $window))
            ->orWhere(fn (Builder $w) => $this->onDays($w->whereNotNull('anniversary'), 'anniversary', $window)))
            ->pluck('id');
        $people = CustomerPerson::query()->where(fn (Builder $q) => $q
            ->where(fn (Builder $w) => $this->onDays($w->whereNotNull('dob'), 'dob', $window))
            ->orWhere(fn (Builder $w) => $this->onDays($w->whereNotNull('anniversary'), 'anniversary', $window)))
            ->pluck('user_id');

        return $own->merge($people)->unique()->values()->all();
    }

    /**
     * Remind customers of occasions `REMIND_DAYS_AHEAD` days out — once a
     * year for each date. Returns how many reminders went out.
     */
    public function sendReminders(): int
    {
        $day = Carbon::now(config('app.business_timezone'))->startOfDay()->addDays(self::REMIND_DAYS_AHEAD);
        $year = $day->year;
        $sent = 0;

        foreach (['dob' => 'birthday', 'anniversary' => 'anniversary'] as $column => $kind) {
            $remindedColumn = "{$column}_reminded_year";

            $users = $this->onDays(User::query()->whereNotNull($column), $column, [$day])
                ->where('role', UserRole::Customer->value)
                ->where('status', UserStatus::Active->value)
                ->where(fn ($q) => $q->whereNull($remindedColumn)->orWhere($remindedColumn, '!=', $year))
                ->get();
            foreach ($users as $user) {
                $title = $kind === 'birthday' ? 'Your birthday is coming up 🎉' : 'Your anniversary is coming up 💐';
                $this->remind($user, $title, 'Celebrate on '.$day->format('j M').'.');
                $user->forceFill([$remindedColumn => $year])->save();
                $sent++;
            }

            $people = $this->onDays(CustomerPerson::query()->whereNotNull($column), $column, [$day])
                ->where(fn ($q) => $q->whereNull($remindedColumn)->orWhere($remindedColumn, '!=', $year))
                ->with('user')
                ->get();
            foreach ($people as $person) {
                $user = $person->user;
                if ($user === null || $user->status !== UserStatus::Active) {
                    continue;
                }
                $title = $kind === 'birthday'
                    ? "{$person->name}’s birthday is on {$day->format('j M')} 🎂"
                    : "{$person->name}’s anniversary is on {$day->format('j M')} 💐";
                $this->remind($user, $title, 'Treat them —');
                $person->forceFill([$remindedColumn => $year])->save();
                $sent++;
            }
        }

        return $sent;
    }

    /** In-app + push, with what the customer's favourite shops offer. */
    private function remind(User $user, string $title, string $lead): void
    {
        $offers = $this->favouriteOffers($user);
        $body = $offers === []
            ? trim($lead.' your favourite shops on Listee have something for every occasion.')
            : trim($lead.' '.implode(' · ', $offers));

        $this->notifications->notify($user, NotificationType::ShopUpdate, $title, $body, ['link' => '/favorites']);
    }

    /**
     * Up to two followed shops with a reward running, as "Shop: ₹50 off …".
     *
     * @return list<string>
     */
    private function favouriteOffers(User $user): array
    {
        $ids = Favorite::where('customer_id', $user->id)->latest('id')->limit(10)->pluck('business_id');
        $out = [];
        foreach (Business::whereIn('id', $ids)->with('rewardSetting')->get() as $shop) {
            $headline = $this->rewards->headline($shop->rewardSetting);
            if ($headline !== null) {
                $out[] = "{$shop->name}: {$headline}";
            }
            if (count($out) === 2) {
                break;
            }
        }

        return $out;
    }
}
