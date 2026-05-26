import { RoomEvent, } from "livekit-client";
import { LIVEKIT_SERVER_RESPONSE_CHANNEL_TOPIC } from "../const";
export const initEventPromise = (room, eventType, rejectionEventType) => {
    return new Promise((resolve, reject) => {
        const messageHandler = (roomMessage, _, __, topic) => {
            if (topic !== LIVEKIT_SERVER_RESPONSE_CHANNEL_TOPIC) {
                return;
            }
            let eventData = null;
            try {
                const messageString = new TextDecoder().decode(roomMessage);
                eventData = JSON.parse(messageString);
            }
            catch (_a) {
                return;
            }
            if (eventData && "event_type" in eventData) {
                const type = eventData.event_type;
                if (type === eventType || type === rejectionEventType) {
                    room.removeListener(RoomEvent.DataReceived, messageHandler);
                    const isRejection = type === rejectionEventType;
                    if (isRejection) {
                        reject();
                    }
                    else {
                        resolve();
                    }
                }
            }
        };
        room.on(RoomEvent.DataReceived, messageHandler);
    });
};
