# XIAO Avionics Beacon Protocol

## Purpose

The Seeed Studio XIAO ESP32-C3 acts as a dedicated avionics-power presence beacon for the IPCA CVR Unit iPhone app. It is powered from the aircraft's switched USB-C outlet through a USB-C splitter.

- Aircraft USB/avionics power ON: the XIAO receives power and advertises.
- Aircraft USB/avionics power OFF: the XIAO loses power and disappears.
- The iPhone app uses advertisement presence as the avionics ON/OFF signal after Admin connects the beacon trigger.

Arduino sketch: `firmware/ipca_avionics_beacon/ipca_avionics_beacon.ino`

Required Arduino setup:

- Board: Seeed Studio XIAO ESP32C3, or another ESP32-C3 board.
- Library: NimBLE-Arduino.
- Pairing: not required.

## BLE Advertisement

- Service UUID: `7A2D5E01-9F83-4C0A-BA18-4B39A2D2E001`
- Advertised local name: `IPCA-AVIONICS`
- Behavior: advertising only
- Pairing: not required
- GATT connection: not required
- Recommended advertising interval: approximately 250 to 500 ms

The iPhone app identifies the beacon primarily by the custom service UUID, not by the local name or iOS `CBPeripheral.identifier`.

The Arduino sketch places the 128-bit service UUID in the primary advertisement and the local name in the scan response. This avoids exceeding the 31-byte BLE legacy advertising payload.

## Expected Boot Behavior

After switched USB-C power is applied, the XIAO should boot and begin advertising as quickly as practical. The app confirms avionics ON after the first matching advertisement is received. This is intentional because iOS may not deliver duplicate advertisements reliably while the iPhone is locked.

When power is removed, the XIAO stops advertising immediately because it is no longer powered.

## Future Manufacturer Data

Manufacturer data is optional for the first test. A future format can include:

- Protocol version
- Aircraft/unit identifier
- Firmware version
- Boot counter
- Uptime seconds
- Checksum

The iPhone diagnostic already logs manufacturer data as hexadecimal when present.

## iOS Timing Logic

Initial diagnostic thresholds:

- Beacon ON confirmation: first matching advertisement
- Temporarily missing: no advertisement for more than 5 seconds
- Avionics OFF confirmation: no advertisement for more than 15 seconds

Diagnostic interpretation:

- Bluetooth unavailable: `UNKNOWN`
- Beacon confirmed and last seen <= 5 seconds: `AVIONICS ON`
- Beacon previously seen and last seen > 15 seconds: `AVIONICS OFF`
- Beacon never seen: `UNKNOWN`

These values are constants in the iPhone diagnostic and can be adjusted after real aircraft testing.

## iOS Background Note

The iPhone app declares Bluetooth central background mode, uses CoreBluetooth state restoration, and keeps listening after Admin enables Connect Beacon. A matching ESP-32 advertisement can trigger recording even when the iPhone is locked.

iOS background BLE scanning can still be throttled by the operating system. Aircraft testing should verify that the app sees power-on transitions reliably with the iPhone locked and unlocked. If the user force-quits the app, iOS generally will not relaunch it for Bluetooth events until the app is opened again.
