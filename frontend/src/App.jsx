import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

const queryClient = new QueryClient()

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <div className="min-h-screen bg-gray-950 text-white">
        <h1 className="text-2xl font-bold p-8">ContentSpy AI</h1>
      </div>
    </QueryClientProvider>
  )
}
