import { lazy, Suspense } from 'react';
import { HashRouter, Route, Routes } from 'react-router-dom';
import { SessionProvider, RequireRole } from './data/session';
import Shell from './components/Shell';
import Landing from './pages/Landing';

// Agent-owned pages (see docs/BUILD-SPEC.md section 7). Each file has a default export.
const Login = lazy(() => import('./pages/auth/Login'));
const Signup = lazy(() => import('./pages/auth/Signup'));
const Dashboard = lazy(() => import('./pages/client/Dashboard'));
const Book = lazy(() => import('./pages/client/Book'));
const MyBookings = lazy(() => import('./pages/client/Bookings'));
const Packs = lazy(() => import('./pages/client/Packs'));
const AdminHome = lazy(() => import('./pages/admin/AdminHome'));
const AdminClients = lazy(() => import('./pages/admin/AdminClients'));
const AdminClient = lazy(() => import('./pages/admin/AdminClient'));
const AdminCalendar = lazy(() => import('./pages/admin/AdminCalendar'));
const AdminConnector = lazy(() => import('./pages/admin/AdminConnector'));
const GoLive = lazy(() => import('./pages/GoLive'));

const Loading = () => <div className="wrap" style={{ padding: '96px 16px' }} aria-busy="true" />;

export default function App() {
  return (
    <HashRouter>
      <SessionProvider>
        <Shell>
          <Suspense fallback={<Loading />}>
            <Routes>
              <Route path="/" element={<Landing />} />
              <Route path="/login" element={<Login />} />
              <Route path="/signup" element={<Signup />} />
              <Route path="/go-live" element={<GoLive />} />
              <Route path="/app" element={<RequireRole role="client"><Dashboard /></RequireRole>} />
              <Route path="/app/book" element={<RequireRole role="client"><Book /></RequireRole>} />
              <Route path="/app/bookings" element={<RequireRole role="client"><MyBookings /></RequireRole>} />
              <Route path="/app/packs" element={<RequireRole role="client"><Packs /></RequireRole>} />
              <Route path="/admin" element={<RequireRole role="owner"><AdminHome /></RequireRole>} />
              <Route path="/admin/clients" element={<RequireRole role="owner"><AdminClients /></RequireRole>} />
              <Route path="/admin/clients/:id" element={<RequireRole role="owner"><AdminClient /></RequireRole>} />
              <Route path="/admin/calendar" element={<RequireRole role="owner"><AdminCalendar /></RequireRole>} />
              <Route path="/admin/chatgpt" element={<RequireRole role="owner"><AdminConnector /></RequireRole>} />
              <Route path="*" element={<Landing />} />
            </Routes>
          </Suspense>
        </Shell>
      </SessionProvider>
    </HashRouter>
  );
}
