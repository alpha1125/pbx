# Browser Softphone Audio Device Checklist

Manual verification for `/poc/browser-softphone`:

1. Load the page over HTTPS.
2. Grant microphone permission when prompted.
3. Confirm microphone and speaker lists populate.
4. Toggle echo cancellation, noise suppression, and auto gain control.
5. Change the microphone selection and confirm the choice persists after refresh.
6. In Chrome or Edge, change the speaker/output device and confirm remote audio follows the selection.
7. Click `Refresh Devices`.
8. Unplug and replug a headset, then confirm the device lists refresh and the UI warns if the selected device disappears.
9. Place a browser call and confirm DTMF and hangup still work.
10. Confirm the applied audio settings display updates after the mic stream is acquired.
11. Use `Test Speaker` to verify the selected output device without starting a call.

Persistence model:

- One browser storage record is used under `pbx.softphone.preferences`.
- Saved fields include microphone, speaker, speaker volume, and audio processing flags.
- Missing devices fall back to browser defaults without clearing the saved preference.
