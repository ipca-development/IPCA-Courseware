# XIAO Avionics Beacon Protocol

## Purpose

The Seeed Studio XIAO ESP32-C3 acts as a dedicated avionics-power presence beacon. It is powered from the aircraft's switched USB-C outlet through a USB-C splitter.

- Aircraft USB/avionics power ON: the XIAO receives power and advertises.
- Aircraft USB/avionics power OFF: the XIAO loses power and disappears.
- The iOS Cockpit Recorder app uses advertisement presence as a diagnostic avionics ON/OFF signal.

This first implementation is foreground diagnostic only. Production recorder auto-start/auto-stop must not be connected until foreground and background behavior have been tested separately.

## BLE Advertisement

- Service UUID: `7A2D5E01-9F83-4C0A-BA18-4B39A2D2E001`
- Advertised local name: `IPCA-AVIONICS`
- Behavior: advertising only
- Pairing: not required
- GATT connection: not required
- Recommended advertising interval: approximately 250 to 500 ms

The iOS app identifies the beacon primarily by the custom service UUID, not by the local name or iOS `CBPeripheral.identifier`.

## Expected Boot Behavior

After switched USB-C power is applied, the XIAO should boot and begin advertising as quickly as practical. The app confirms avionics ON after at least two matching advertisements are received within the configured confirmation window.

When power is removed, the XIAO stops advertising immediately because it is no longer powered.

## Future Manufacturer Data

Manufacturer data is optional for the first test. A future format can include:

- Protocol version
- Aircraft/unit identifier
- Firmware version
- Boot counter
- Uptime seconds
- Checksum

The iOS diagnostic already logs manufacturer data as hexadecimal when present.

## iOS Timing Logic

Initial diagnostic thresholds:

- Beacon ON confirmation: at least 2 advertisements within 5 seconds
- Temporarily missing: no advertisement for more than 5 seconds
- Avionics OFF confirmation: no advertisement for more than 15 seconds

Diagnostic interpretation:

- Bluetooth unavailable: `UNKNOWN`
- Beacon confirmed and last seen <= 5 seconds: `AVIONICS ON`
- Beacon previously seen and last seen > 15 seconds: `AVIONICS OFF`
- Beacon never seen: `UNKNOWN`

These values are constants in the iOS diagnostic and can be adjusted after real aircraft testing.

## iOS Background Note

The first diagnostic uses foreground CoreBluetooth scanning. iOS background BLE scanning behaves differently, including scan throttling and service filtering, and must be tested separately before using this beacon to drive production recorder auto-start/auto-stop.
