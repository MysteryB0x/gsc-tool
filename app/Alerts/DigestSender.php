<?php

namespace App\Alerts;

use App\Mail\ChangeDigest;
use App\Models\AlertDigest;
use App\Models\AlertPreference;
use App\Models\Team;
use App\Models\User;
use App\Support\Branding;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends each person their daily or weekly digest for each workspace, only when something big changed,
 * and never twice for the same period. Email and in-app (the bell) per their settings.
 */
class DigestSender
{
    /** @return int digests sent */
    public function send(string $period): int
    {
        $sent = 0;
        Team::with(['users', 'plan'])->whereIn('subscription_status', ['active', 'trialing'])->each(function (Team $team) use ($period, &$sent) {
            foreach ($team->users as $user) {
                $pref = AlertPreference::for($team, $user);
                if ($pref->{$period} && $this->sendTo($team, $user, $pref, $period)) {
                    $sent++;
                }
            }
        });

        return $sent;
    }

    /** @return list<array> the changes, without sending anything */
    public function preview(Team $team, AlertPreference $pref, string $period): array
    {
        $detector = new ChangeDetector($pref->min_change, $pref->min_clicks);
        $properties = $team->properties()->where('is_active', true)
            ->when($pref->property_ids, fn ($q, $ids) => $q->whereIn('id', $ids))->orderBy('name')->get();

        $changes = $detector->forTeam($team);
        foreach ($properties as $property) {
            array_push($changes, ...$detector->forProperty($property, $period));
        }
        usort($changes, fn (Change $a, Change $b) => $b->weight <=> $a->weight);

        return array_map(fn (Change $c) => $c->toArray(), array_slice($changes, 0, 25));
    }

    public function sendTo(Team $team, User $user, AlertPreference $pref, string $period): bool
    {
        $items = $this->preview($team, $pref, $period);
        if (! $items) {
            return false;
        }

        $periodEnd = today()->toDateString();
        $digest = AlertDigest::firstOrNew(['team_id' => $team->id, 'user_id' => $user->id, 'period' => $period, 'period_end' => $periodEnd]);
        if ($digest->exists) {
            return false;
        }
        $digest->items = $items;
        $digest->save();

        $title = ($period === 'daily' ? 'Daily' : 'Weekly').' changes in '.$team->name.': '.count($items).' '.str('item')->plural(count($items));

        if ($pref->in_app) {
            Notification::make()
                ->title($title)
                ->body(collect($items)->take(3)->map(fn ($i) => $i['property'].': '.$i['title'])->implode("\n"))
                ->icon($items[0]['kind'] === 'down' || $items[0]['kind'] === 'problem' ? 'heroicon-o-arrow-trending-down' : 'heroicon-o-bell')
                ->sendToDatabase($user);
        }

        if ($pref->email) {
            try {
                config(['app.name' => Branding::name()]);
                Mail::to($user)->send(new ChangeDigest($team, $period, $items, $title));
                $digest->update(['emailed' => true]);
            } catch (Throwable $e) {
                $digest->update(['error' => $e->getMessage()]);
                Log::warning('Change digest email failed', ['team' => $team->id, 'user' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        return true;
    }
}
