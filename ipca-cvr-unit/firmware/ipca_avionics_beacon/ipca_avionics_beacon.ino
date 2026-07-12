#include <NimBLEDevice.h>

// IPCA CVR Unit avionics-power BLE beacon.
//
// Hardware target:
// - Seeed Studio XIAO ESP32-C3, or another ESP32-C3 board supported by Arduino.
//
// Behavior:
// - When aircraft switched USB-C/avionics power is ON, the ESP32-C3 boots and advertises.
// - When aircraft switched USB-C/avionics power is OFF, the ESP32-C3 loses power and disappears.
// - The iPhone app listens for this service UUID. Pairing and GATT connection are not required.

static const char* DEVICE_NAME = "IPCA-AVIONICS";
static const char* SERVICE_UUID = "7A2D5E01-9F83-4C0A-BA18-4B39A2D2E001";

static const uint16_t ADVERTISING_INTERVAL_MIN = 400;  // 400 * 0.625 ms = 250 ms
static const uint16_t ADVERTISING_INTERVAL_MAX = 800;  // 800 * 0.625 ms = 500 ms

void setup() {
  NimBLEDevice::init(DEVICE_NAME);
  NimBLEDevice::setPower(ESP_PWR_LVL_P9);

  NimBLEAdvertising* advertising = NimBLEDevice::getAdvertising();

  NimBLEAdvertisementData advertisementData;
  advertisementData.setCompleteServices(NimBLEUUID(SERVICE_UUID));

  NimBLEAdvertisementData scanResponseData;
  scanResponseData.setName(DEVICE_NAME);

  advertising->setAdvertisementData(advertisementData);
  advertising->setScanResponseData(scanResponseData);
  advertising->setMinInterval(ADVERTISING_INTERVAL_MIN);
  advertising->setMaxInterval(ADVERTISING_INTERVAL_MAX);
  advertising->start();
}

void loop() {
  delay(1000);
}
