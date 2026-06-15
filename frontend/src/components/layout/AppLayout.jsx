import { useState } from 'react';
import Sidebar from './Sidebar';
import AppHeader from './AppHeader';

export default function AppLayout({ children }) {
  const [collapsed, setCollapsed] = useState(false);

  return (
    <div className="flex min-h-screen bg-slate-50">
      <Sidebar collapsed={collapsed} onToggle={() => setCollapsed((v) => !v)} />

      <div
        className={`flex min-h-screen flex-1 flex-col transition-all duration-300 ease-in-out ${
          collapsed ? 'ml-[72px]' : 'ml-64'
        }`}
      >
        <AppHeader collapsed={collapsed} onToggleSidebar={() => setCollapsed((v) => !v)} />

        <main className="flex-1 p-6">
          {children}
        </main>
      </div>
    </div>
  );
}
