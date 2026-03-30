import React from "react";
import { cn } from "../../lib/utils";
import { Tabs as UiTabs, TabsList, TabsTrigger } from "../ui/tabs";

interface Tab {
  id: string;
  label: string;
  icon?: React.ElementType;
  count?: number;
}

interface TabsProps {
  tabs: Tab[];
  activeTab: string;
  onChange: (id: string) => void;
  className?: string;
}

export function Tabs({ tabs, activeTab, onChange, className = "" }: TabsProps) {
  return (
    <UiTabs value={activeTab} onValueChange={onChange} className={className}>
      <TabsList className="h-auto flex-wrap justify-start">
        {tabs.map((tab) => {
          const Icon = tab.icon;

          return (
            <TabsTrigger key={tab.id} value={tab.id} className="gap-2">
              {Icon ? <Icon className="h-4 w-4" /> : null}
              {tab.label}
              {tab.count !== undefined ? (
                <span
                  className={cn(
                    "rounded-full border px-2 py-0.5 text-[11px]",
                    activeTab === tab.id ? "border-border bg-background text-foreground" : "border-transparent text-muted-foreground",
                  )}
                >
                  {tab.count}
                </span>
              ) : null}
            </TabsTrigger>
          );
        })}
      </TabsList>
    </UiTabs>
  );
}
