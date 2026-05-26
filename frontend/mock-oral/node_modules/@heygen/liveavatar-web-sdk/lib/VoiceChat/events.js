export var VoiceChatEvent;
(function (VoiceChatEvent) {
    VoiceChatEvent["MUTED"] = "MUTED";
    VoiceChatEvent["UNMUTED"] = "UNMUTED";
    // DEVICE_CHANGED = "DEVICE_CHANGED",
    VoiceChatEvent["STATE_CHANGED"] = "STATE_CHANGED";
})(VoiceChatEvent || (VoiceChatEvent = {}));
export var PushToTalkCommandEvent;
(function (PushToTalkCommandEvent) {
    PushToTalkCommandEvent["START"] = "user.start_push_to_talk";
    PushToTalkCommandEvent["STOP"] = "user.stop_push_to_talk";
})(PushToTalkCommandEvent || (PushToTalkCommandEvent = {}));
export var PushToTalkServerEvent;
(function (PushToTalkServerEvent) {
    PushToTalkServerEvent["START_SUCCESS"] = "user.push_to_talk_started";
    PushToTalkServerEvent["START_FAILED"] = "user.push_to_talk_start_failed";
    PushToTalkServerEvent["STOP_SUCCESS"] = "user.push_to_talk_stopped";
    PushToTalkServerEvent["STOP_FAILED"] = "user.push_to_talk_stop_failed";
})(PushToTalkServerEvent || (PushToTalkServerEvent = {}));
