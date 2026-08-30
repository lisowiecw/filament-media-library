<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Lisowiecw\MediaLibrary\Derivatives\DerivativeHealth;

/**
 * The three numbers that say whether the rendering pipeline is keeping up, and
 * the one button that acts on them.
 *
 * Nothing in the package sweeps: a failed rendering has exhausted its retries,
 * a stale one is still served, and a missing one waits for someone to open the
 * asset. So this readout is the only place a settings change or a run of bad
 * uploads becomes visible without opening a terminal.
 *
 * Regenerating queues a bounded batch, because this runs in a request rather
 * than in the command that can afford to trickle at the configured cap. What is
 * left over is reported with the command's name, so the operator is pointed at
 * the tool that can finish the job instead of pressing this repeatedly.
 */
final readonly class DerivativeHealthReadout
{
    public static function make(): Action
    {
        return Action::make('derivativeHealth')
            ->label(__('media-library::messages.management.actions.health'))
            ->icon('heroicon-m-heart')
            ->color('gray')
            ->modalHeading(__('media-library::messages.management.modals.health'))
            ->modalSubmitActionLabel(__('media-library::messages.management.actions.regenerate'))
            ->schema(fn (): array => [
                Text::make(self::summary()),
            ])
            ->action(function (): void {
                ['queued' => $queued, 'remaining' => $remaining] = DerivativeHealth::regenerate();

                Notification::make()
                    ->title(__('media-library::messages.management.notifications.regenerating', ['count' => $queued]))
                    ->body($remaining === 0
                        ? null
                        : (string) __('media-library::messages.management.notifications.regenerate_remaining', ['count' => $remaining]))
                    ->status($remaining === 0 ? 'success' : 'warning')
                    ->send();
            });
    }

    /**
     * The counts read at the moment the modal opens, so what an operator is
     * about to act on is what they were just shown.
     */
    private static function summary(): string
    {
        return (string) __('media-library::messages.management.health.summary', DerivativeHealth::counts());
    }
}
