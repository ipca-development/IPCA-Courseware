#include <NimBLEDevice.h>
#include <Preferences.h>
#include <esp_system.h>
#include <esp_task_wdt.h>
#include <esp_idf_version.h>

// IPCA CVR Unit avionics-power BLE beacon.
//
// Hardware target:
// - Seeed Studio XIAO ESP32-C3, or another ESP32-C3 board supported by Arduino.
//
// Behavior:
// - When aircraft switched USB-C/avionics power is ON, the ESP32-C3 boots and advertises.
// - When aircraft switched USB-C/avionics power is OFF, the ESP32-C3 loses power and disappears.
// - The iPhone app uses advertisements for discovery and GATT for the active relationship.
// - The beacon reports hardware/contact facts only. It does not know recording sessions.

static const char* DEVICE_NAME = "IPCA-AVIONICS";
static const char* SERVICE_UUID = "7A2D5E01-9F83-4C0A-BA18-4B39A2D2E001";
static const char* STATUS_CHARACTERISTIC_UUID = "7A2D5E02-9F83-4C0A-BA18-4B39A2D2E001";
static const char* RECORDER_CONTACT_CHARACTERISTIC_UUID = "7A2D5E03-9F83-4C0A-BA18-4B39A2D2E001";

static const uint16_t ADVERTISING_INTERVAL_MIN = 400;  // 400 * 0.625 ms = 250 ms
static const uint16_t ADVERTISING_INTERVAL_MAX = 800;  // 800 * 0.625 ms = 500 ms
static const uint8_t PROTOCOL_VERSION = 1;
static const uint8_t FIRMWARE_VERSION_MAJOR = 1;
static const uint8_t FIRMWARE_VERSION_MINOR = 0;
static const uint8_t FIRMWARE_VERSION_PATCH = 0;
static const uint8_t RESET_REASON_UNKNOWN = 0;
static const uint8_t RESET_REASON_POWER_ON = 1;
static const uint8_t RESET_REASON_BROWNOUT = 2;
static const uint8_t RESET_REASON_WATCHDOG = 3;
static const uint8_t RESET_REASON_SOFTWARE_RESTART = 4;
static const uint8_t RESET_REASON_PANIC = 5;
static const uint8_t RESET_REASON_DEEP_SLEEP = 6;
static const uint8_t USB_DIAGNOSTIC_UNAVAILABLE = 0;
static const uint32_t NEVER_CONTACTED_UPTIME = 0xFFFFFFFF;
static const size_t STATUS_LENGTH = 60;
static const size_t RECORDER_CONTACT_LENGTH = 26;
static const uint32_t ADVERTISEMENT_REFRESH_INTERVAL_MS = 5000;

Preferences preferences;
NimBLECharacteristic* statusCharacteristic = nullptr;

uint32_t bootCounter = 0;
uint8_t bootUuid[16] = {0};
uint32_t advertisementCounter = 0;
uint8_t resetReason = RESET_REASON_UNKNOWN;
uint8_t recorderToken[16] = {0};
uint32_t lastRecorderContactUptime = NEVER_CONTACTED_UPTIME;
uint32_t lastAdvertisementRefreshMs = 0;

uint32_t uptimeSeconds() {
  return millis() / 1000U;
}

void putUInt16LE(uint8_t* buffer, size_t offset, uint16_t value) {
  buffer[offset] = static_cast<uint8_t>(value & 0xFF);
  buffer[offset + 1] = static_cast<uint8_t>((value >> 8) & 0xFF);
}

void putUInt32LE(uint8_t* buffer, size_t offset, uint32_t value) {
  buffer[offset] = static_cast<uint8_t>(value & 0xFF);
  buffer[offset + 1] = static_cast<uint8_t>((value >> 8) & 0xFF);
  buffer[offset + 2] = static_cast<uint8_t>((value >> 16) & 0xFF);
  buffer[offset + 3] = static_cast<uint8_t>((value >> 24) & 0xFF);
}

uint32_t getUInt32LE(const uint8_t* buffer, size_t offset) {
  return static_cast<uint32_t>(buffer[offset]) |
         (static_cast<uint32_t>(buffer[offset + 1]) << 8) |
         (static_cast<uint32_t>(buffer[offset + 2]) << 16) |
         (static_cast<uint32_t>(buffer[offset + 3]) << 24);
}

uint8_t mapResetReason(esp_reset_reason_t reason) {
  switch (reason) {
    case ESP_RST_POWERON:
      return RESET_REASON_POWER_ON;
    case ESP_RST_BROWNOUT:
      return RESET_REASON_BROWNOUT;
    case ESP_RST_TASK_WDT:
    case ESP_RST_WDT:
      return RESET_REASON_WATCHDOG;
    case ESP_RST_SW:
      return RESET_REASON_SOFTWARE_RESTART;
    case ESP_RST_PANIC:
      return RESET_REASON_PANIC;
    case ESP_RST_DEEPSLEEP:
      return RESET_REASON_DEEP_SLEEP;
    default:
      return RESET_REASON_UNKNOWN;
  }
}

void generateBootUuid() {
  esp_fill_random(bootUuid, sizeof(bootUuid));
}

void loadAndIncrementBootCounter() {
  preferences.begin("ipcaBeacon", false);
  bootCounter = preferences.getUInt("bootCounter", 0) + 1;
  preferences.putUInt("bootCounter", bootCounter);
  preferences.end();
}

std::string beaconIdentifierFingerprint() {
  uint64_t mac = ESP.getEfuseMac();
  char fingerprint[9];
  snprintf(fingerprint, sizeof(fingerprint), "%08X", static_cast<uint32_t>(mac & 0xFFFFFFFF));
  return std::string(fingerprint);
}

std::string statusPayload() {
  uint8_t buffer[STATUS_LENGTH] = {0};
  buffer[0] = PROTOCOL_VERSION;
  putUInt32LE(buffer, 1, bootCounter);
  memcpy(buffer + 5, bootUuid, sizeof(bootUuid));
  putUInt32LE(buffer, 21, advertisementCounter);
  buffer[25] = resetReason;
  buffer[26] = FIRMWARE_VERSION_MAJOR;
  buffer[27] = FIRMWARE_VERSION_MINOR;
  buffer[28] = FIRMWARE_VERSION_PATCH;
  putUInt32LE(buffer, 29, uptimeSeconds());
  memcpy(buffer + 33, recorderToken, sizeof(recorderToken));
  putUInt32LE(buffer, 49, lastRecorderContactUptime);
  buffer[53] = USB_DIAGNOSTIC_UNAVAILABLE;
  putUInt16LE(buffer, 54, 0);
  putUInt32LE(buffer, 56, 0);
  return std::string(reinterpret_cast<const char*>(buffer), STATUS_LENGTH);
}

void publishStatus(bool notify = true) {
  if (statusCharacteristic == nullptr) {
    return;
  }
  statusCharacteristic->setValue(statusPayload());
  if (notify) {
    statusCharacteristic->notify();
  }
}

void configureAdvertisement() {
  advertisementCounter++;

  NimBLEAdvertising* advertising = NimBLEDevice::getAdvertising();

  NimBLEAdvertisementData advertisementData;
  advertisementData.setCompleteServices(NimBLEUUID(SERVICE_UUID));

  NimBLEAdvertisementData scanResponseData;
  scanResponseData.setName(DEVICE_NAME);

  uint8_t manufacturerData[11] = {0};
  // 0xFFFF is a reserved test company identifier. The payload is diagnostic only.
  manufacturerData[0] = 0xFF;
  manufacturerData[1] = 0xFF;
  manufacturerData[2] = PROTOCOL_VERSION;
  uint32_t macFingerprint = static_cast<uint32_t>(ESP.getEfuseMac() & 0xFFFFFFFF);
  putUInt32LE(manufacturerData, 3, macFingerprint);
  putUInt16LE(manufacturerData, 7, static_cast<uint16_t>(bootCounter & 0xFFFF));
  manufacturerData[9] = bootUuid[0];
  manufacturerData[10] = bootUuid[1];
  scanResponseData.setManufacturerData(std::string(reinterpret_cast<const char*>(manufacturerData), sizeof(manufacturerData)));

  advertising->stop();
  advertising->setAdvertisementData(advertisementData);
  advertising->setScanResponseData(scanResponseData);
  advertising->setMinInterval(ADVERTISING_INTERVAL_MIN);
  advertising->setMaxInterval(ADVERTISING_INTERVAL_MAX);
  advertising->start();
  publishStatus(false);
}

void setupWatchdog() {
#if ESP_IDF_VERSION_MAJOR >= 5
  esp_task_wdt_config_t config = {
    .timeout_ms = 10000,
    .idle_core_mask = (1 << portNUM_PROCESSORS) - 1,
    .trigger_panic = true,
  };
  esp_task_wdt_init(&config);
#else
  esp_task_wdt_init(10, true);
#endif
  esp_task_wdt_add(NULL);
}

class RecorderContactCallbacks : public NimBLECharacteristicCallbacks {
  void handleWrite(NimBLECharacteristic* characteristic) {
    std::string value = characteristic->getValue();
    if (value.size() != RECORDER_CONTACT_LENGTH) {
      return;
    }
    const uint8_t* bytes = reinterpret_cast<const uint8_t*>(value.data());
    if (bytes[0] != PROTOCOL_VERSION) {
      return;
    }
    (void)getUInt32LE(bytes, 17);  // Recorder sequence is diagnostic for the iPhone side.
    memcpy(recorderToken, bytes + 1, sizeof(recorderToken));
    lastRecorderContactUptime = uptimeSeconds();
    publishStatus(true);
  }

  void onWrite(NimBLECharacteristic* characteristic) {
    handleWrite(characteristic);
  }

  void onWrite(NimBLECharacteristic* characteristic, NimBLEConnInfo& connInfo) {
    (void)connInfo;
    handleWrite(characteristic);
  }
};

class StatusCallbacks : public NimBLECharacteristicCallbacks {
  void onRead(NimBLECharacteristic* characteristic) {
    characteristic->setValue(statusPayload());
  }

  void onRead(NimBLECharacteristic* characteristic, NimBLEConnInfo& connInfo) {
    (void)connInfo;
    characteristic->setValue(statusPayload());
  }
};

void setup() {
  resetReason = mapResetReason(esp_reset_reason());
  loadAndIncrementBootCounter();
  generateBootUuid();
  setupWatchdog();

  NimBLEDevice::init(DEVICE_NAME);
  NimBLEDevice::setPower(ESP_PWR_LVL_P9);
  NimBLEDevice::setSecurityAuth(false, false, false);

  NimBLEServer* server = NimBLEDevice::createServer();
  NimBLEService* service = server->createService(SERVICE_UUID);

  statusCharacteristic = service->createCharacteristic(
    STATUS_CHARACTERISTIC_UUID,
    NIMBLE_PROPERTY::READ | NIMBLE_PROPERTY::NOTIFY
  );
  statusCharacteristic->setCallbacks(new StatusCallbacks());
  statusCharacteristic->setValue(statusPayload());

  NimBLECharacteristic* recorderContactCharacteristic = service->createCharacteristic(
    RECORDER_CONTACT_CHARACTERISTIC_UUID,
    NIMBLE_PROPERTY::WRITE | NIMBLE_PROPERTY::WRITE_NR
  );
  recorderContactCharacteristic->setCallbacks(new RecorderContactCallbacks());

  service->start();
  configureAdvertisement();
  lastAdvertisementRefreshMs = millis();
}

void loop() {
  esp_task_wdt_reset();
  uint32_t now = millis();
  if (now - lastAdvertisementRefreshMs >= ADVERTISEMENT_REFRESH_INTERVAL_MS) {
    configureAdvertisement();
    lastAdvertisementRefreshMs = now;
  }
  delay(250);
}
