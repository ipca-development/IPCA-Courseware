import SwiftUI
import UIKit

/// UIKit-backed text field — avoids SwiftUI TextField focus/input bugs on iPad.
struct UIKitTextField: UIViewRepresentable {
    @Binding var text: String
    var placeholder: String
    var keyboardType: UIKeyboardType = .default
    var isSecure: Bool = false
    var returnKeyType: UIReturnKeyType = .next
    var onReturn: (() -> Void)?

    func makeCoordinator() -> Coordinator {
        Coordinator(parent: self)
    }

    func makeUIView(context: Context) -> UITextField {
        let field = UITextField(frame: .zero)
        field.delegate = context.coordinator
        field.placeholder = placeholder
        field.borderStyle = .roundedRect
        field.keyboardType = keyboardType
        field.returnKeyType = returnKeyType
        field.autocapitalizationType = .none
        field.autocorrectionType = .no
        field.spellCheckingType = .no
        field.smartDashesType = .no
        field.smartQuotesType = .no
        field.smartInsertDeleteType = .no
        field.textContentType = isSecure ? .password : .none
        field.isSecureTextEntry = isSecure
        field.text = text
        field.clearButtonMode = .whileEditing
        field.enablesReturnKeyAutomatically = false
        field.addTarget(
            context.coordinator,
            action: #selector(Coordinator.editingChanged(_:)),
            for: .editingChanged
        )
        return field
    }

    func updateUIView(_ uiView: UITextField, context: Context) {
        context.coordinator.parent = self
        if uiView.text != text {
            uiView.text = text
        }
        uiView.isSecureTextEntry = isSecure
        uiView.returnKeyType = returnKeyType
    }

    final class Coordinator: NSObject, UITextFieldDelegate {
        var parent: UIKitTextField

        init(parent: UIKitTextField) {
            self.parent = parent
        }

        @objc func editingChanged(_ sender: UITextField) {
            parent.text = sender.text ?? ""
        }

        func textFieldShouldReturn(_ textField: UITextField) -> Bool {
            parent.onReturn?()
            return true
        }
    }
}
