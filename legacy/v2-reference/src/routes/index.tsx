import { createFileRoute } from "@tanstack/react-router";
import { GrillApp } from "@/components/grill/app";

export const Route = createFileRoute("/")({ component: Home });

function Home() {
  return <GrillApp />;
}
