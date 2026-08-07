import SwiftUI

struct ProfilePickerView: View {
    @Environment(FilmotecaModel.self) private var app
    @State private var viewModel: ProfilePickerViewModel
    @State private var isManaging = false
    @State private var confirmDelete = false

    init(container: AppContainer) {
        _viewModel = State(initialValue: ProfilePickerViewModel(container: container))
    }

    private let colors: [Color] = [FilmotecaTheme.accent, .purple, .blue, .cyan, .orange, .green]

    var body: some View {
        ZStack {
            FilmotecaTheme.background.ignoresSafeArea()
            VStack(spacing: 35) {
                HStack {
                    FilmotecaWordmark()
                    Spacer()
                    Button(isManaging ? "Gata" : "Gestionează") { withAnimation { isManaging.toggle() } }
                        .foregroundStyle(FilmotecaTheme.accent)
                    Button("Închide") { app.profilePickerPresented = false }.foregroundStyle(.white.opacity(0.7))
                }
                .padding(.horizontal, 22)
                Spacer()
                Text("Cine vizionează?").font(.largeTitle.bold())
                LazyVGrid(columns: [GridItem(.adaptive(minimum: 120), spacing: 25)], spacing: 28) {
                    ForEach(Array((app.user?.profiles ?? []).enumerated()), id: \.element.id) { index, profile in
                        Button {
                            if isManaging { viewModel.beginEditing(profile) }
                            else { app.selectProfile(profile) }
                        } label: {
                            VStack(spacing: 11) {
                                ZStack {
                                    RoundedRectangle(cornerRadius: 26).fill(LinearGradient(colors: [colors[index % colors.count], colors[(index + 2) % colors.count]], startPoint: .topLeading, endPoint: .bottomTrailing))
                                    Text(profile.avatarLabel ?? String(profile.name.prefix(1))).font(.system(size: 42, weight: .black))
                                    if profile.isKids == true { Text("KIDS").font(.system(size: 9, weight: .black)).padding(6).background(.black.opacity(0.5), in: Capsule()).padding(8).frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomTrailing) }
                                    if isManaging {
                                        Image(systemName: "pencil")
                                            .padding(10)
                                            .background(.black.opacity(0.65), in: Circle())
                                    }
                                }
                                .frame(width: 112, height: 112)
                                .overlay(RoundedRectangle(cornerRadius: 26).stroke(profile.id == app.activeProfile?.id ? .white : .clear, lineWidth: 3))
                                Text(profile.name).font(.headline).foregroundStyle(.white)
                            }
                        }.buttonStyle(.plain)
                    }
                    if (app.user?.profiles?.count ?? 0) < 3 {
                        Button { viewModel.isCreatePresented = true } label: { VStack(spacing: 11) { RoundedRectangle(cornerRadius: 26).fill(FilmotecaTheme.surface).frame(width: 112, height: 112).overlay { Image(systemName: "plus").font(.largeTitle).foregroundStyle(.white.opacity(0.7)) }.overlay(RoundedRectangle(cornerRadius: 26).stroke(FilmotecaTheme.hairline)); Text("Profil nou").font(.headline).foregroundStyle(.white) } }.buttonStyle(.plain)
                    }
                }.padding(.horizontal, 28)
                Spacer()
            }.padding(.vertical, 22)
        }
        .sheet(isPresented: Binding(get: { viewModel.isCreatePresented }, set: { viewModel.isCreatePresented = $0 })) {
            NavigationStack {
                Form { TextField("Numele profilului", text: Binding(get: { viewModel.name }, set: { viewModel.name = $0 })); Toggle("Profil pentru copii", isOn: Binding(get: { viewModel.isKids }, set: { viewModel.isKids = $0 })) }
                    .navigationTitle("Profil nou")
                    .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Anulează") { viewModel.isCreatePresented = false } }; ToolbarItem(placement: .confirmationAction) { Button("Creează") { Task { await viewModel.create(app: app) } }.disabled(viewModel.name.trimmingCharacters(in: .whitespaces).isEmpty) } }
            }.presentationDetents([.medium])
        }
        .sheet(isPresented: Binding(get: { viewModel.isEditPresented }, set: { viewModel.isEditPresented = $0 })) {
            NavigationStack {
                Form {
                    TextField("Numele profilului", text: Binding(get: { viewModel.name }, set: { viewModel.name = $0 }))
                    Toggle("Profil pentru copii", isOn: Binding(get: { viewModel.isKids }, set: { viewModel.isKids = $0 }))
                    Section {
                        Button("Șterge profilul", role: .destructive) { confirmDelete = true }
                            .disabled((app.user?.profiles?.count ?? 0) <= 1)
                    } footer: {
                        if (app.user?.profiles?.count ?? 0) <= 1 {
                            Text("Contul trebuie să păstreze cel puțin un profil.")
                        }
                    }
                }
                .navigationTitle("Editează profilul")
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) { Button("Anulează") { viewModel.isEditPresented = false } }
                    ToolbarItem(placement: .confirmationAction) {
                        Button("Salvează") { Task { await viewModel.update(app: app) } }
                            .disabled(viewModel.name.trimmingCharacters(in: .whitespaces).isEmpty)
                    }
                }
                .alert("Ștergi profilul?", isPresented: $confirmDelete) {
                    Button("Șterge", role: .destructive) { Task { await viewModel.delete(app: app) } }
                    Button("Anulează", role: .cancel) {}
                } message: {
                    Text("Favoritele și progresul asociate profilului vor fi eliminate.")
                }
            }
            .presentationDetents([.medium])
        }
    }

}
