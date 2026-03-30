import React from 'react';
import { useLocation } from 'react-router-dom';
export function Footer() {
  const location = useLocation();
  // Hide footer on player page and auth/profile pages
  if (
  location.pathname.startsWith('/watch') ||
  location.pathname === '/auth' ||
  location.pathname === '/profiles')
  {
    return null;
  }
  return (
    <footer className="bg-background border-t border-white/5 py-12 mt-20">
      <div className="container mx-auto px-4 md:px-8">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-8 mb-8">
          <div>
            <h3 className="text-2xl font-bold tracking-tighter text-white mb-4">
              film<span className="text-accent">.</span>md
            </h3>
            <p className="text-sm text-gray-500">
              Premium pay-per-content streaming. No subscriptions, just great
              movies.
            </p>
          </div>

          <div>
            <h4 className="text-white font-medium mb-4">Navigation</h4>
            <ul className="space-y-2 text-sm text-gray-400">
              <li>
                <a href="#" className="hover:text-white transition-colors">
                  Home
                </a>
              </li>
              <li>
                <a href="#" className="hover:text-white transition-colors">
                  Movies
                </a>
              </li>
              <li>
                <a href="#" className="hover:text-white transition-colors">
                  Series
                </a>
              </li>
              <li>
                <a href="#" className="hover:text-white transition-colors">
                  Trending
                </a>
              </li>
            </ul>
          </div>

          <div>
            <h4 className="text-white font-medium mb-4">Support</h4>
            <ul className="space-y-2 text-sm text-gray-400">
              <li>
                <a href="#" className="hover:text-white transition-colors">
                  FAQ
                </a>
              </li>
              <li>
                <a href="#" className="hover:text-white transition-colors">
                  Contact Us
                </a>
              </li>
              <li>
                <a href="#" className="hover:text-white transition-colors">
                  Wallet Help
                </a>
              </li>
            </ul>
          </div>

          <div>
            <h4 className="text-white font-medium mb-4">Legal</h4>
            <ul className="space-y-2 text-sm text-gray-400">
              <li>
                <a href="#" className="hover:text-white transition-colors">
                  Terms of Service
                </a>
              </li>
              <li>
                <a href="#" className="hover:text-white transition-colors">
                  Privacy Policy
                </a>
              </li>
              <li>
                <a href="#" className="hover:text-white transition-colors">
                  Cookie Policy
                </a>
              </li>
            </ul>
          </div>
        </div>

        <div className="pt-8 border-t border-white/5 flex flex-col md:flex-row items-center justify-between text-xs text-gray-600">
          <p>&copy; {new Date().getFullYear()} film.md. All rights reserved.</p>
          <div className="flex space-x-4 mt-4 md:mt-0">
            <span>English</span>
            <span>Română</span>
            <span>Русский</span>
          </div>
        </div>
      </div>
    </footer>);

}