import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export const useAuthStore = create(
  persist(
    (set, get) => ({
      token: null,
      user: null,
      workspace: null,

      setAuth: (token, user, workspace) => set({ token, user, workspace }),

      setWorkspace: (workspace) => set({ workspace }),

      logout: () => {
        set({ token: null, user: null, workspace: null })
      },

      isAuthenticated: () => !!get().token,
    }),
    {
      name: 'contentspy-auth',
      partialize: (state) => ({ token: state.token, user: state.user, workspace: state.workspace }),
    }
  )
)
