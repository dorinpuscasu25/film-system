import React from 'react';
import { motion } from 'framer-motion';
interface Tab {
  id: string;
  label: string;
}
interface TabsProps {
  tabs: Tab[];
  activeTab: string;
  onTabChange: (id: string) => void;
}
export function Tabs({ tabs, activeTab, onTabChange }: TabsProps) {
  return (
    <div className="flex space-x-8 border-b border-white/10 relative overflow-x-auto hide-scrollbar">
      {tabs.map((tab) =>
      <button
        key={tab.id}
        onClick={() => onTabChange(tab.id)}
        className={`pb-4 text-lg font-medium transition-colors relative whitespace-nowrap ${activeTab === tab.id ? 'text-white' : 'text-gray-400 hover:text-gray-200'}`}>
        
          {tab.label}
          {activeTab === tab.id &&
        <motion.div
          layoutId="activeTabIndicator"
          className="absolute bottom-0 left-0 right-0 h-1 bg-accent rounded-t-full"
          initial={false}
          transition={{
            type: 'spring',
            stiffness: 500,
            damping: 30
          }} />

        }
        </button>
      )}
    </div>);

}