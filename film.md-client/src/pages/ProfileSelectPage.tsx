import React, { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { AnimatePresence, motion } from "framer-motion";
import { Edit2Icon, PlusIcon, Trash2Icon, XIcon } from "lucide-react";
import { useAuth } from "../contexts/AuthContext";
import { ProfileAvatar } from "../components/ProfileAvatar";
import { UserProfile } from "../types";

const PROFILE_COLORS = [
  "from-blue-500 to-purple-600",
  "from-pink-500 to-rose-500",
  "from-green-400 to-emerald-600",
  "from-orange-500 to-red-500",
  "from-cyan-500 to-blue-600",
  "from-violet-500 to-purple-600",
];

export function ProfileSelectPage() {
  const { user, selectProfile, createProfile, updateProfile, deleteProfile } = useAuth();
  const navigate = useNavigate();
  const [isManaging, setIsManaging] = useState(false);
  const [isEditorOpen, setIsEditorOpen] = useState(false);
  const [editingProfile, setEditingProfile] = useState<UserProfile | null>(null);
  const [name, setName] = useState("");
  const [avatarColor, setAvatarColor] = useState(PROFILE_COLORS[0]);
  const [isKids, setIsKids] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => {
    if (!user) {
      navigate("/");
    }
  }, [navigate, user]);

  const isCreateMode = editingProfile === null;

  const sortedProfiles = useMemo(
    () => [...(user?.profiles ?? [])].sort((left, right) => Number(right.isDefault) - Number(left.isDefault)),
    [user?.profiles],
  );

  const resetEditor = () => {
    setEditingProfile(null);
    setName("");
    setAvatarColor(PROFILE_COLORS[0]);
    setIsKids(false);
    setErrorMessage(null);
  };

  const openCreateModal = () => {
    resetEditor();
    setIsEditorOpen(true);
  };

  const openEditModal = (profile: UserProfile) => {
    setEditingProfile(profile);
    setName(profile.name);
    setAvatarColor(profile.color || PROFILE_COLORS[0]);
    setIsKids(profile.isKids);
    setErrorMessage(null);
    setIsEditorOpen(true);
  };

  const handleSelect = (profileId: string) => {
    if (isManaging) {
      const profile = sortedProfiles.find((item) => item.id === profileId);
      if (profile) {
        openEditModal(profile);
      }
      return;
    }

    selectProfile(profileId);
    navigate("/");
  };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setErrorMessage(null);
    setIsSaving(true);

    try {
      const payload = {
        name: name.trim(),
        avatarColor,
        avatarLabel: name.trim().charAt(0).toUpperCase(),
        isKids,
      };

      if (editingProfile) {
        await updateProfile(editingProfile.id, payload);
      } else {
        await createProfile(payload);
      }

      setIsEditorOpen(false);
      resetEditor();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "We could not save the profile.");
    } finally {
      setIsSaving(false);
    }
  };

  const handleDelete = async (profile: UserProfile) => {
    const confirmed = window.confirm(`Delete profile "${profile.name}"?`);
    if (!confirmed) {
      return;
    }

    setErrorMessage(null);
    setIsSaving(true);

    try {
      await deleteProfile(profile.id);
      if (editingProfile?.id === profile.id) {
        setIsEditorOpen(false);
        resetEditor();
      }
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "We could not delete the profile.");
    } finally {
      setIsSaving(false);
    }
  };

  if (!user) {
    return null;
  }

  const containerVariants = {
    hidden: { opacity: 0 },
    show: {
      opacity: 1,
      transition: {
        staggerChildren: 0.1,
      },
    },
  };

  const itemVariants = {
    hidden: { opacity: 0, scale: 0.8 },
    show: { opacity: 1, scale: 1 },
  };

  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-background p-4">
      <div className="absolute left-8 top-8">
        <h1 className="text-3xl font-bold tracking-tighter text-white">
          filmoteca<span className="text-accent">.</span>md
        </h1>
      </div>

      <motion.div initial={{ opacity: 0, y: -20 }} animate={{ opacity: 1, y: 0 }} className="mb-12 text-center">
        <h2 className="text-4xl font-medium text-white md:text-5xl">{isManaging ? "Manage Profiles" : "Who's watching?"}</h2>
        <p className="mt-3 text-sm text-gray-400">
          {isManaging ? "Create separate viewing spaces for adults and kids." : "Choose the profile you want to watch with."}
        </p>
      </motion.div>

      {errorMessage && (
        <div className="mb-6 w-full max-w-xl rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
          {errorMessage}
        </div>
      )}

      <motion.div
        variants={containerVariants}
        initial="hidden"
        animate="show"
        className="flex max-w-4xl flex-wrap justify-center gap-8 md:gap-12"
      >
        {sortedProfiles.map((profile) => (
          <motion.div key={profile.id} variants={itemVariants} className="relative group">
            <div className={`relative ${isManaging ? "cursor-pointer hover:opacity-90" : ""}`}>
              <ProfileAvatar profile={profile} size="xl" onClick={() => handleSelect(profile.id)} />

              {profile.isKids && (
                <span className="absolute -bottom-2 left-1/2 -translate-x-1/2 rounded-full border border-emerald-500/30 bg-emerald-500/15 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-emerald-200">
                  Kids
                </span>
              )}

              {isManaging && (
                <div className="pointer-events-none absolute inset-0 flex items-center justify-center rounded-full bg-black/50">
                  <Edit2Icon className="h-8 w-8 text-white" />
                </div>
              )}
            </div>

            {isManaging && !profile.isDefault && (
              <button
                onClick={() => void handleDelete(profile)}
                disabled={isSaving}
                className="absolute -right-2 -top-2 rounded-full border border-white/10 bg-surfaceHover p-2 transition-colors hover:border-accent hover:bg-accent"
              >
                <Trash2Icon className="h-4 w-4 text-white" />
              </button>
            )}
          </motion.div>
        ))}

        {user.profiles.length < 5 && (
          <motion.div variants={itemVariants} className="flex flex-col items-center">
            <button
              onClick={openCreateModal}
              className="group flex h-32 w-32 items-center justify-center rounded-full border-2 border-dashed border-gray-600 transition-all duration-300 hover:border-white hover:bg-white/5"
            >
              <PlusIcon className="h-12 w-12 text-gray-600 transition-colors group-hover:text-white" />
            </button>
            <span className="mt-4 text-xl font-medium text-gray-500">Add Profile</span>
          </motion.div>
        )}
      </motion.div>

      <motion.button
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ delay: 0.5 }}
        onClick={() => setIsManaging((value) => !value)}
        className={`mt-16 rounded border px-6 py-2 text-lg uppercase tracking-widest transition-colors ${
          isManaging
            ? "border-white bg-white text-background hover:bg-gray-200"
            : "border-gray-500 text-gray-400 hover:border-white hover:text-white"
        }`}
      >
        {isManaging ? "Done" : "Manage Profiles"}
      </motion.button>

      <AnimatePresence>
        {isEditorOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              className="absolute inset-0 bg-black/80 backdrop-blur-sm"
              onClick={() => setIsEditorOpen(false)}
            />

            <motion.div
              initial={{ scale: 0.95, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.95, opacity: 0 }}
              className="glass-panel relative z-10 w-full max-w-md rounded-2xl border border-white/10 p-8 shadow-2xl"
            >
              <button
                onClick={() => setIsEditorOpen(false)}
                className="absolute right-4 top-4 text-gray-400 transition-colors hover:text-white"
              >
                <XIcon className="h-6 w-6" />
              </button>

              <h3 className="mb-6 text-2xl font-bold text-white">{isCreateMode ? "Add Profile" : "Edit Profile"}</h3>

              <form onSubmit={handleSubmit} className="space-y-6">
                <div>
                  <label className="mb-2 block text-sm font-medium text-gray-400">Name</label>
                  <input
                    type="text"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    className="w-full rounded-lg border border-white/10 bg-surfaceHover px-4 py-3 text-white focus:border-accent focus:outline-none"
                    placeholder="Profile Name"
                    required
                  />
                </div>

                <div>
                  <label className="mb-2 block text-sm font-medium text-gray-400">Color Theme</label>
                  <div className="grid grid-cols-6 gap-2">
                    {PROFILE_COLORS.map((color) => (
                      <button
                        key={color}
                        type="button"
                        onClick={() => setAvatarColor(color)}
                        className={`h-10 w-10 rounded-full bg-gradient-to-br ${color} ${
                          avatarColor === color ? "ring-2 ring-white ring-offset-2 ring-offset-background" : ""
                        }`}
                      />
                    ))}
                  </div>
                </div>

                <div className="flex items-center justify-between rounded-lg border border-white/5 bg-surfaceHover p-4">
                  <div>
                    <h4 className="font-medium text-white">Kids Profile</h4>
                    <p className="text-xs text-gray-400">Show only family-friendly viewing experiences.</p>
                  </div>
                  <button
                    type="button"
                    onClick={() => setIsKids((value) => !value)}
                    className={`relative h-6 w-12 rounded-full transition-colors ${isKids ? "bg-accentGreen" : "bg-gray-600"}`}
                  >
                    <motion.div
                      className="absolute top-0.5 h-5 w-5 rounded-full bg-white"
                      animate={{ left: isKids ? "26px" : "2px" }}
                      transition={{ type: "spring", stiffness: 500, damping: 30 }}
                    />
                  </button>
                </div>

                <button
                  type="submit"
                  disabled={isSaving}
                  className="w-full rounded-lg bg-accent py-3 font-bold text-white transition-colors hover:bg-red-700"
                >
                  {isSaving ? "Saving..." : isCreateMode ? "Create Profile" : "Save Changes"}
                </button>
              </form>

              {!isCreateMode && editingProfile && !editingProfile.isDefault && (
                <button
                  type="button"
                  disabled={isSaving}
                  onClick={() => void handleDelete(editingProfile)}
                  className="mt-4 w-full rounded-lg border border-red-500/30 bg-red-500/10 py-3 font-medium text-red-200 transition-colors hover:bg-red-500/20"
                >
                  Delete Profile
                </button>
              )}
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </div>
  );
}
