import GameController
import SwiftUI
import UIKit

/// UITextField tuned for iPad hardware keyboards (Magic Keyboard, etc.).
final class HardwareKeyboardTextField: UITextField {
    var onTabForward: (() -> Void)?
    var onTabBackward: (() -> Void)?

    override var canBecomeFirstResponder: Bool { true }

    override func touchesEnded(_ touches: Set<UITouch>, with event: UIEvent?) {
        super.touchesEnded(touches, with: event)
        if !isFirstResponder {
            _ = becomeFirstResponder()
        }
    }

    override func pressesEnded(_ presses: Set<UIPress>, with event: UIPressesEvent?) {
        var handledTab = false
        for press in presses {
            guard let key = press.key, key.keyCode == .keyboardTab else { continue }
            if key.modifierFlags.contains(.shift) {
                onTabBackward?()
            } else {
                onTabForward?()
            }
            handledTab = true
        }
        if handledTab {
            return
        }
        super.pressesEnded(presses, with: event)
    }
}

/// Pure UIKit sign-in screen — avoids SwiftUI keyboard routing bugs on iPad.
final class LoginFormViewController: UIViewController {
    var initialServerURL: String = ""

    private let scrollView = UIScrollView()
    private let contentStack = UIStackView()
    private let serverField = HardwareKeyboardTextField()
    private let emailField = HardwareKeyboardTextField()
    private let passwordField = HardwareKeyboardTextField()
    private let errorLabel = UILabel()
    private let signInButton = UIButton(type: .system)
    private let activityIndicator = UIActivityIndicatorView(style: .medium)

    private var fields: [HardwareKeyboardTextField] {
        [serverField, emailField, passwordField]
    }

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .systemGroupedBackground
        title = "Manuals"
        navigationItem.largeTitleDisplayMode = .always
        configureFields()
        configureLayout()
        configureKeyboardObservers()
        serverField.text = initialServerURL
    }

    override func viewDidAppear(_ animated: Bool) {
        super.viewDidAppear(animated)
        // Hardware keyboard: field can look focused while keys route elsewhere until
        // first responder is explicitly established after the hierarchy is on-screen.
        DispatchQueue.main.async { [weak self] in
            guard let self, self.serverField.isFirstResponder == false else { return }
            if self.view.window?.isKeyWindow == true {
                _ = self.serverField.becomeFirstResponder()
            }
        }
    }

    deinit {
        NotificationCenter.default.removeObserver(self)
    }

    private func configureFields() {
        configureField(
            serverField,
            placeholder: "courseware.example.com or full https URL",
            returnKey: .next
        )
        configureField(
            emailField,
            placeholder: "you@example.com",
            returnKey: .next
        )
        configureField(
            passwordField,
            placeholder: "Password",
            returnKey: .go,
            isSecure: true
        )

        wireTabOrder()

        for field in fields {
            field.delegate = self
        }

        errorLabel.font = .preferredFont(forTextStyle: .footnote)
        errorLabel.textColor = .systemRed
        errorLabel.numberOfLines = 0
        errorLabel.isHidden = true

        var config = UIButton.Configuration.filled()
        config.title = "Sign In"
        config.cornerStyle = .medium
        signInButton.configuration = config
        signInButton.addTarget(self, action: #selector(signInTapped), for: .touchUpInside)

        activityIndicator.hidesWhenStopped = true
    }

    private func configureField(
        _ field: HardwareKeyboardTextField,
        placeholder: String,
        returnKey: UIReturnKeyType,
        isSecure: Bool = false
    ) {
        field.placeholder = placeholder
        field.borderStyle = .roundedRect
        // .URL / .emailAddress can block hardware key routing on iPad; use .default.
        field.keyboardType = .default
        field.returnKeyType = returnKey
        field.autocapitalizationType = .none
        field.autocorrectionType = .no
        field.spellCheckingType = .no
        field.smartDashesType = .no
        field.smartQuotesType = .no
        field.smartInsertDeleteType = .no
        field.clearButtonMode = .whileEditing
        field.isSecureTextEntry = isSecure
        field.textContentType = isSecure ? .password : .none
        field.translatesAutoresizingMaskIntoConstraints = false
        field.heightAnchor.constraint(greaterThanOrEqualToConstant: 44).isActive = true
        field.inputAssistantItem.leadingBarButtonGroups = []
        field.inputAssistantItem.trailingBarButtonGroups = []
    }

    private func wireTabOrder() {
        for (index, field) in fields.enumerated() {
            field.onTabForward = { [weak self] in
                self?.focusField(at: index + 1)
            }
            field.onTabBackward = { [weak self] in
                self?.focusField(at: index - 1)
            }
        }
    }

    private func focusField(at index: Int) {
        guard fields.indices.contains(index) else { return }
        let field = fields[index]
        _ = field.becomeFirstResponder()
    }

    private func configureLayout() {
        scrollView.translatesAutoresizingMaskIntoConstraints = false
        scrollView.keyboardDismissMode = .none
        view.addSubview(scrollView)

        contentStack.axis = .vertical
        contentStack.spacing = 20
        contentStack.translatesAutoresizingMaskIntoConstraints = false
        scrollView.addSubview(contentStack)

        let intro = UILabel()
        intro.text = "Sign in to read released OM/OMM manuals."
        intro.font = .preferredFont(forTextStyle: .subheadline)
        intro.textColor = .secondaryLabel
        intro.numberOfLines = 0

        contentStack.addArrangedSubview(intro)
        contentStack.addArrangedSubview(labeledBlock(title: "IPCA Server", field: serverField))
        contentStack.addArrangedSubview(labeledBlock(title: "Email", field: emailField))
        contentStack.addArrangedSubview(labeledBlock(title: "Password", field: passwordField))
        contentStack.addArrangedSubview(errorLabel)

        let buttonRow = UIStackView(arrangedSubviews: [signInButton, activityIndicator])
        buttonRow.axis = .horizontal
        buttonRow.spacing = 12
        buttonRow.alignment = .center
        contentStack.addArrangedSubview(buttonRow)

        let tip = UILabel()
        tip.text = "Tip: include https:// if your server requires it. Tab moves between fields."
        tip.font = .preferredFont(forTextStyle: .caption1)
        tip.textColor = .tertiaryLabel
        tip.textAlignment = .center
        tip.numberOfLines = 0
        tip.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(tip)

        NSLayoutConstraint.activate([
            scrollView.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor),
            scrollView.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            scrollView.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            scrollView.bottomAnchor.constraint(equalTo: tip.topAnchor, constant: -8),

            contentStack.topAnchor.constraint(equalTo: scrollView.contentLayoutGuide.topAnchor, constant: 24),
            contentStack.leadingAnchor.constraint(equalTo: scrollView.frameLayoutGuide.leadingAnchor, constant: 24),
            contentStack.trailingAnchor.constraint(equalTo: scrollView.frameLayoutGuide.trailingAnchor, constant: -24),
            contentStack.bottomAnchor.constraint(equalTo: scrollView.contentLayoutGuide.bottomAnchor, constant: -24),
            contentStack.widthAnchor.constraint(lessThanOrEqualToConstant: 520),
            contentStack.centerXAnchor.constraint(equalTo: scrollView.frameLayoutGuide.centerXAnchor),

            signInButton.heightAnchor.constraint(greaterThanOrEqualToConstant: 48),
            tip.leadingAnchor.constraint(equalTo: view.leadingAnchor, constant: 24),
            tip.trailingAnchor.constraint(equalTo: view.trailingAnchor, constant: -24),
            tip.bottomAnchor.constraint(equalTo: view.safeAreaLayoutGuide.bottomAnchor, constant: -12)
        ])
    }

    private func labeledBlock(title: String, field: UITextField) -> UIStackView {
        let titleLabel = UILabel()
        titleLabel.text = title
        titleLabel.font = .preferredFont(forTextStyle: .headline)

        let stack = UIStackView(arrangedSubviews: [titleLabel, field])
        stack.axis = .vertical
        stack.spacing = 8
        return stack
    }

    private func configureKeyboardObservers() {
        let center = NotificationCenter.default
        center.addObserver(
            self,
            selector: #selector(keyboardWillChange(_:)),
            name: UIResponder.keyboardWillChangeFrameNotification,
            object: nil
        )
        center.addObserver(
            self,
            selector: #selector(hardwareKeyboardChanged),
            name: NSNotification.Name.GCKeyboardDidConnect,
            object: nil
        )
        center.addObserver(
            self,
            selector: #selector(hardwareKeyboardChanged),
            name: NSNotification.Name.GCKeyboardDidDisconnect,
            object: nil
        )
    }

    @objc private func hardwareKeyboardChanged() {
        guard let active = fields.first(where: \.isFirstResponder) else { return }
        active.reloadInputViews()
    }

    @objc private func keyboardWillChange(_ notification: Notification) {
        guard
            let frame = notification.userInfo?[UIResponder.keyboardFrameEndUserInfoKey] as? CGRect,
            let duration = notification.userInfo?[UIResponder.keyboardAnimationDurationUserInfoKey] as? Double
        else { return }

        // Hardware keyboard reports zero-height frame — don't adjust insets.
        guard frame.height > 80 else {
            scrollView.contentInset.bottom = 0
            scrollView.verticalScrollIndicatorInsets.bottom = 0
            return
        }

        let keyboardInView = view.convert(frame, from: nil)
        let overlap = max(0, view.bounds.maxY - keyboardInView.minY - view.safeAreaInsets.bottom)
        scrollView.contentInset.bottom = overlap + 16
        scrollView.verticalScrollIndicatorInsets.bottom = overlap

        UIView.animate(withDuration: duration) {
            self.view.layoutIfNeeded()
        }
    }

    @objc private func signInTapped() {
        view.endEditing(true)
        Task { await performSignIn() }
    }

    @MainActor
    private func performSignIn() async {
        let server = serverField.text?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        let email = emailField.text?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        let password = passwordField.text ?? ""

        guard !server.isEmpty, !email.isEmpty, !password.isEmpty else {
            showError("Enter server URL, email, and password.")
            return
        }

        setBusy(true)
        errorLabel.isHidden = true

        let session = ManualReaderSessionStore.shared
        do {
            try session.setServerURL(server)
            try await session.login(email: email, password: password)
        } catch {
            session.clearSession()
            showError(error.localizedDescription)
        }

        setBusy(false)
    }

    private func showError(_ message: String) {
        errorLabel.text = message
        errorLabel.isHidden = false
    }

    private func setBusy(_ busy: Bool) {
        signInButton.isEnabled = !busy
        serverField.isEnabled = !busy
        emailField.isEnabled = !busy
        passwordField.isEnabled = !busy
        if busy {
            activityIndicator.startAnimating()
        } else {
            activityIndicator.stopAnimating()
        }
    }
}

extension LoginFormViewController: UITextFieldDelegate {
    func textFieldDidBeginEditing(_ textField: UITextField) {
        textField.reloadInputViews()
    }

    func textFieldShouldReturn(_ textField: UITextField) -> Bool {
        switch textField {
        case serverField:
            _ = emailField.becomeFirstResponder()
        case emailField:
            _ = passwordField.becomeFirstResponder()
        case passwordField:
            Task { await performSignIn() }
        default:
            textField.resignFirstResponder()
        }
        return true
    }
}

struct LoginFormView: UIViewControllerRepresentable {
    func makeUIViewController(context: Context) -> UINavigationController {
        let login = LoginFormViewController()
        login.initialServerURL = ManualReaderSessionStore.shared.baseURL?.absoluteString ?? ""
        let navigation = UINavigationController(rootViewController: login)
        navigation.navigationBar.prefersLargeTitles = true
        return navigation
    }

    func updateUIViewController(_ uiViewController: UINavigationController, context: Context) {
        // UIKit owns field state while editing.
    }
}
