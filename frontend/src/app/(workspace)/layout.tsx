"use client";

import { WorkspaceProvider } from "@/components/WorkspaceProvider";
import Shell from "@/components/Shell";

export default function WorkspaceLayout({ children }: { children: React.ReactNode }) {
  return (
    <WorkspaceProvider>
      <Shell>{children}</Shell>
    </WorkspaceProvider>
  );
}
