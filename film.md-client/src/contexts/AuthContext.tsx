import React, { ReactNode, createContext, useContext, useEffect, useState } from "react";
import { User, UserProfile } from "../types";
import {
  SessionProfilePayload,
  SessionUserPayload,
  clearAuthToken,
  createStorefrontProfile,
  deleteStorefrontProfile,
  fetchCurrentUser,
  loginWithPassword,
  logoutCurrentUser,
  readAuthToken,
  registerWithPassword,
  resendRegistrationCode,
  updateAccountPassword,
  updateAccountSettings,
  updateStorefrontProfile,
  verifyRegistrationCode,
  writeAuthToken,
} from "../lib/session";

const PROFILE_COLORS = [
  "from-blue-500 to-purple-600",
  "from-pink-500 to-rose-500",
  "from-green-400 to-emerald-600",
  "from-orange-500 to-red-500",
  "from-cyan-500 to-blue-600",
  "from-violet-500 to-purple-600",
];

const ACTIVE_PROFILE_STORAGE_PREFIX = "film_active_profile_";

interface PendingRegistrationState {
  email: string;
  expiresAt?: string | null;
}

interface ProfileMutationInput {
  name: string;
  avatarColor?: string;
  avatarLabel?: string;
  isKids?: boolean;
}

interface AuthContextType {
  isAuthenticated: boolean;
  user: User | null;
  activeProfile: UserProfile | null;
  pendingRegistration: PendingRegistrationState | null;
  isAuthModalOpen: boolean;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (name: string, email: string, password: string) => Promise<void>;
  verifyRegistration: (code: string) => Promise<void>;
  resendRegistration: (email?: string) => Promise<void>;
  startVerification: (email: string, expiresAt?: string | null) => void;
  clearPendingRegistration: () => void;
  logout: () => void;
  selectProfile: (profileId: string) => void;
  createProfile: (payload: ProfileMutationInput) => Promise<UserProfile | null>;
  updateProfile: (profileId: string, payload: ProfileMutationInput) => Promise<UserProfile | null>;
  deleteProfile: (profileId: string) => Promise<void>;
  updateAccount: (payload: { name: string; email: string; preferredLocale?: "en" | "ro" | "ru" }) => Promise<void>;
  changePassword: (currentPassword: string, password: string) => Promise<void>;
  openAuthModal: () => void;
  closeAuthModal: () => void;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

function colorForUser(userId: string) {
  const hash = Array.from(userId).reduce((total, character) => total + character.charCodeAt(0), 0);
  return PROFILE_COLORS[hash % PROFILE_COLORS.length];
}

function activeProfileStorageKey(userId: string) {
  return `${ACTIVE_PROFILE_STORAGE_PREFIX}${userId}`;
}

function defaultProfileForUser(sessionUser: SessionUserPayload): UserProfile {
  const name = sessionUser.name?.trim() || sessionUser.email.split("@")[0];

  return {
    id: "main",
    name,
    avatarUrl: name.charAt(0).toUpperCase(),
    avatarLabel: name.charAt(0).toUpperCase(),
    isKids: false,
    color: colorForUser(String(sessionUser.id)),
    isDefault: true,
    favoriteSlugs: [],
  };
}

function mapProfile(sessionUser: SessionUserPayload, profile: SessionProfilePayload): UserProfile {
  const name = typeof profile.name === "string" && profile.name.trim() !== ""
    ? profile.name.trim()
    : defaultProfileForUser(sessionUser).name;
  const avatarLabel = typeof profile.avatar_label === "string" && profile.avatar_label.trim() !== ""
    ? profile.avatar_label.trim()
    : name.charAt(0).toUpperCase();

  return {
    id: String(profile.id),
    name,
    avatarUrl: avatarLabel,
    avatarLabel,
    isKids: Boolean(profile.is_kids),
    color: typeof profile.avatar_color === "string" && profile.avatar_color.trim() !== ""
      ? profile.avatar_color
      : colorForUser(String(sessionUser.id)),
    isDefault: Boolean(profile.is_default),
    favoriteSlugs: Array.isArray(profile.favorite_slugs)
      ? profile.favorite_slugs.filter((value): value is string => typeof value === "string")
      : [],
  };
}

function resolveProfiles(sessionUser: SessionUserPayload): UserProfile[] {
  if (!Array.isArray(sessionUser.profiles) || sessionUser.profiles.length === 0) {
    return [defaultProfileForUser(sessionUser)];
  }

  return sessionUser.profiles.map((profile) => mapProfile(sessionUser, profile));
}

function mapSessionUser(sessionUser: SessionUserPayload): User {
  return {
    id: String(sessionUser.id),
    email: sessionUser.email,
    name: sessionUser.name,
    preferredLocale: sessionUser.preferred_locale ?? undefined,
    profiles: resolveProfiles(sessionUser),
  };
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [activeProfile, setActiveProfile] = useState<UserProfile | null>(null);
  const [pendingRegistration, setPendingRegistration] = useState<PendingRegistrationState | null>(null);
  const [isAuthModalOpen, setIsAuthModalOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(true);

  const applySessionUser = (sessionUser: SessionUserPayload, preferredActiveProfileId?: string | null) => {
    const mappedUser = mapSessionUser(sessionUser);
    const storedActiveProfileId = preferredActiveProfileId !== undefined && preferredActiveProfileId !== null
      ? String(preferredActiveProfileId)
      : localStorage.getItem(activeProfileStorageKey(mappedUser.id));
    const resolvedActiveProfile = mappedUser.profiles.find((profile) => profile.id === storedActiveProfileId)
      ?? mappedUser.profiles.find((profile) => profile.isDefault)
      ?? mappedUser.profiles[0]
      ?? null;

    setUser(mappedUser);
    setActiveProfile(resolvedActiveProfile);

    if (resolvedActiveProfile) {
      localStorage.setItem(activeProfileStorageKey(mappedUser.id), resolvedActiveProfile.id);
    }
  };

  const applyProfiles = (profiles: SessionProfilePayload[], preferredActiveProfileId?: string | null) => {
    if (!user) {
      return null;
    }

    const nextUser = mapSessionUser({
      id: user.id,
      email: user.email,
      name: user.name,
      preferred_locale: user.preferredLocale as "en" | "ro" | "ru" | undefined,
      profiles,
    });
    const storedActiveProfileId = preferredActiveProfileId !== undefined && preferredActiveProfileId !== null
      ? String(preferredActiveProfileId)
      : activeProfile?.id ?? localStorage.getItem(activeProfileStorageKey(nextUser.id));
    const nextActiveProfile = nextUser.profiles.find((profile) => profile.id === storedActiveProfileId)
      ?? nextUser.profiles.find((profile) => profile.isDefault)
      ?? nextUser.profiles[0]
      ?? null;

    setUser(nextUser);
    setActiveProfile(nextActiveProfile);

    if (nextActiveProfile) {
      localStorage.setItem(activeProfileStorageKey(nextUser.id), nextActiveProfile.id);
    }

    return nextActiveProfile;
  };

  useEffect(() => {
    const token = readAuthToken();

    if (!token) {
      setIsLoading(false);
      return;
    }

    let active = true;

    async function bootstrapSession() {
      try {
        const response = await fetchCurrentUser();
        if (!active) {
          return;
        }

        applySessionUser(response.user);
      } catch {
        if (active) {
          clearAuthToken();
          setUser(null);
          setActiveProfile(null);
        }
      } finally {
        if (active) {
          setIsLoading(false);
        }
      }
    }

    void bootstrapSession();

    return () => {
      active = false;
    };
  }, []);

  const login = async (email: string, password: string) => {
    const response = await loginWithPassword(email, password);
    writeAuthToken(response.token);
    applySessionUser(response.user);
    setPendingRegistration(null);
    setIsAuthModalOpen(false);
  };

  const register = async (name: string, email: string, password: string) => {
    const response = await registerWithPassword({
      name,
      email,
      password,
    });

    setPendingRegistration({
      email: response.email,
      expiresAt: response.expires_at ?? null,
    });
  };

  const verifyRegistration = async (code: string) => {
    if (!pendingRegistration?.email) {
      throw new Error("Start registration first so we know where to confirm the code.");
    }

    const response = await verifyRegistrationCode(pendingRegistration.email, code);
    writeAuthToken(response.token);
    applySessionUser(response.user);
    setPendingRegistration(null);
    setIsAuthModalOpen(false);
  };

  const resendRegistration = async (email?: string) => {
    const targetEmail = email ?? pendingRegistration?.email;

    if (!targetEmail) {
      throw new Error("We need an email address before sending a confirmation code.");
    }

    const response = await resendRegistrationCode(targetEmail);
    setPendingRegistration({
      email: response.email,
      expiresAt: response.expires_at ?? null,
    });
  };

  const startVerification = (email: string, expiresAt?: string | null) => {
    setPendingRegistration({
      email,
      expiresAt: expiresAt ?? null,
    });
  };

  const clearPendingRegistration = () => {
    setPendingRegistration(null);
  };

  const logout = () => {
    const token = readAuthToken();

    if (token) {
      void logoutCurrentUser().catch(() => undefined);
    }

    setUser(null);
    setActiveProfile(null);
    setPendingRegistration(null);
    clearAuthToken();
  };

  const selectProfile = (profileId: string) => {
    if (!user) {
      return;
    }

    const profile = user.profiles.find((item) => item.id === profileId);
    if (!profile) {
      return;
    }

    setActiveProfile(profile);
    localStorage.setItem(activeProfileStorageKey(user.id), profileId);
  };

  const createProfile = async (payload: ProfileMutationInput) => {
    const response = await createStorefrontProfile(payload);
    return applyProfiles(response.profiles, response.profile?.id ? String(response.profile.id) : null);
  };

  const updateProfile = async (profileId: string, payload: ProfileMutationInput) => {
    const response = await updateStorefrontProfile(profileId, payload);
    return applyProfiles(response.profiles, response.profile?.id ? String(response.profile.id) : activeProfile?.id ?? null);
  };

  const deleteProfile = async (profileId: string) => {
    const response = await deleteStorefrontProfile(profileId);
    applyProfiles(response.profiles, activeProfile?.id === profileId ? null : activeProfile?.id ?? null);
  };

  const updateAccount = async (payload: { name: string; email: string; preferredLocale?: "en" | "ro" | "ru" }) => {
    const response = await updateAccountSettings(payload);
    applySessionUser(response.user, activeProfile?.id ?? null);
  };

  const changePassword = async (currentPassword: string, password: string) => {
    await updateAccountPassword({
      currentPassword,
      password,
    });
  };

  const openAuthModal = () => setIsAuthModalOpen(true);
  const closeAuthModal = () => setIsAuthModalOpen(false);

  return (
    <AuthContext.Provider
      value={{
        isAuthenticated: !!user,
        user,
        activeProfile,
        pendingRegistration,
        isAuthModalOpen,
        isLoading,
        login,
        register,
        verifyRegistration,
        resendRegistration,
        startVerification,
        clearPendingRegistration,
        logout,
        selectProfile,
        createProfile,
        updateProfile,
        deleteProfile,
        updateAccount,
        changePassword,
        openAuthModal,
        closeAuthModal,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error("useAuth must be used within an AuthProvider");
  }
  return context;
}
