import Foundation

enum CVRArchiveRecordRecoveryError: LocalizedError {
    case expectedArray
    case unterminatedString
    case unbalancedContainer

    var errorDescription: String? {
        switch self {
        case .expectedArray:
            return "The workflow archive is not a JSON array."
        case .unterminatedString:
            return "The workflow archive contains an unterminated JSON string."
        case .unbalancedContainer:
            return "The workflow archive contains an unbalanced JSON record."
        }
    }
}

struct CVRArchiveRecordRecovery {
    static func records(in data: Data) throws -> [Data] {
        let bytes = Array(data)
        guard let first = bytes.firstIndex(where: { !isWhitespace($0) }),
              let last = bytes.lastIndex(where: { !isWhitespace($0) }),
              bytes[first] == 0x5B,
              bytes[last] == 0x5D else {
            throw CVRArchiveRecordRecoveryError.expectedArray
        }

        var records: [Data] = []
        var recordStart: Int?
        var depth = 0
        var inString = false
        var escaped = false
        var index = first + 1

        while index < last {
            let byte = bytes[index]
            if recordStart == nil {
                if isWhitespace(byte) || byte == 0x2C {
                    index += 1
                    continue
                }
                recordStart = index
            }

            if inString {
                if escaped {
                    escaped = false
                } else if byte == 0x5C {
                    escaped = true
                } else if byte == 0x22 {
                    inString = false
                }
            } else {
                switch byte {
                case 0x22:
                    inString = true
                case 0x7B, 0x5B:
                    depth += 1
                case 0x7D, 0x5D:
                    depth -= 1
                    if depth < 0 {
                        throw CVRArchiveRecordRecoveryError.unbalancedContainer
                    }
                case 0x2C where depth == 0:
                    if let start = recordStart {
                        records.append(trimmedData(bytes[start..<index]))
                    }
                    recordStart = nil
                default:
                    break
                }
            }
            index += 1
        }

        guard !inString else {
            throw CVRArchiveRecordRecoveryError.unterminatedString
        }
        guard depth == 0 else {
            throw CVRArchiveRecordRecoveryError.unbalancedContainer
        }
        if let start = recordStart {
            records.append(trimmedData(bytes[start..<last]))
        }
        return records.filter { !$0.isEmpty }
    }

    private static func isWhitespace(_ byte: UInt8) -> Bool {
        byte == 0x20 || byte == 0x09 || byte == 0x0A || byte == 0x0D
    }

    private static func trimmedData(_ slice: ArraySlice<UInt8>) -> Data {
        guard let first = slice.firstIndex(where: { !isWhitespace($0) }),
              let last = slice.lastIndex(where: { !isWhitespace($0) }) else {
            return Data()
        }
        return Data(slice[first...last])
    }
}
