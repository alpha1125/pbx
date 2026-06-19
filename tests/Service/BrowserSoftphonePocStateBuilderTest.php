<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrowserSoftphonePocStateBuilder;
use PHPUnit\Framework\TestCase;

final class BrowserSoftphonePocStateBuilderTest extends TestCase
{
    public function testControlStateUsesMutedAndLiveLabels(): void
    {
        $builder = new BrowserSoftphonePocStateBuilder();

        self::assertSame([
            'browserCallLabel' => 'Browser Call',
            'muteButtonLabel' => 'Mute',
            'hangupButtonLabel' => 'Hangup',
            'dialpadButtonLabel' => 'Dialpad',
            'settingsButtonLabel' => 'Settings ⚙',
            'indicatorLabel' => 'Live 🟢',
            'indicatorVariant' => 'success',
            'keyboardShortcutPlaceholder' => 'Keyboard shortcut reserved for a later phase.',
        ], $builder->buildControlState(false));

        self::assertSame('Unmute', $builder->buildControlState(true)['muteButtonLabel']);
        self::assertSame('Muted 🔴', $builder->buildControlState(true)['indicatorLabel']);
        self::assertSame('danger', $builder->buildControlState(true)['indicatorVariant']);
    }

    public function testSettingsStateMarksPanelCollapsedByDefault(): void
    {
        $builder = new BrowserSoftphonePocStateBuilder();

        self::assertSame([
            'isOpen' => false,
            'isMobile' => false,
            'collapsedByDefault' => true,
            'title' => 'Settings',
            'sections' => ['Audio Devices', 'Audio Processing', 'Diagnostics'],
        ], $builder->buildSettingsState(false, false));
    }
}
