# IPCA (iPhone / iPad)

Native daily IPCA application. Sign in with IPCA.training, then Messages, Training, and Community.

## Open

```
open ipca-app-ios/IPCA.xcodeproj
```

Set the Development Team, then run on an iPhone or iPad. Enrollment is: install → sign in → done.

## First launch

1. Sign in with your IPCA.training email and password.
2. Messages opens as home.
3. The app asks to turn on notifications so instructor and staff messages can reach you.
4. After sign-in you appear as reachable for a DM, even if notifications are still off.

DEBUG builds show a server URL field. The default production server is `https://ipca.training`.

The session token is stored in Keychain, never UserDefaults.

Unsent messages stay in the local outbox when the network drops and send when connectivity returns.
