import SwiftUI

struct LoginView: View {
    var body: some View {
        // UIKit navigation only — SwiftUI NavigationStack steals hardware keyboard input on iPad.
        LoginFormView()
    }
}
