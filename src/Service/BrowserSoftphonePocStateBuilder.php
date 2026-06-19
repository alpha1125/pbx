<?php

declare(strict_types=1);

namespace App\Service;

final class BrowserSoftphonePocStateBuilder
{
    /**
     * @return array{
     *     browserCallLabel:string,
     *     muteButtonLabel:string,
     *     hangupButtonLabel:string,
     *     dialpadButtonLabel:string,
     *     settingsButtonLabel:string,
     *     indicatorLabel:string,
     *     indicatorVariant:string,
     *     keyboardShortcutPlaceholder:string
     * }
     */
    public function buildControlState(bool $muted): array
    {
        return [
            'browserCallLabel' => 'Browser Call',
            'muteButtonLabel' => $muted ? 'Unmute' : 'Mute',
            'hangupButtonLabel' => 'Hangup',
            'dialpadButtonLabel' => 'Dialpad',
            'settingsButtonLabel' => 'Settings ⚙',
            'indicatorLabel' => $muted ? 'Muted 🔴' : 'Live 🟢',
            'indicatorVariant' => $muted ? 'danger' : 'success',
            'keyboardShortcutPlaceholder' => 'Keyboard shortcut reserved for a later phase.',
        ];
    }

    /**
     * @return array{
     *     isOpen:bool,
     *     isMobile:bool,
     *     collapsedByDefault:bool,
     *     title:string,
     *     sections:list<string>
     * }
     */
    public function buildSettingsState(bool $isOpen, bool $isMobile): array
    {
        return [
            'isOpen' => $isOpen,
            'isMobile' => $isMobile,
            'collapsedByDefault' => true,
            'title' => 'Settings',
            'sections' => ['Audio Devices', 'Audio Processing', 'Diagnostics'],
        ];
    }
}
