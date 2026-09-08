/**
 * @file
 * Header utilities for Government layout (Search toggle, Scroll states, Submenu toggles).
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.governmentHeader = {
    attach: function (context) {
      // Search dropdown toggle
      once('government-search', '.government-search-toggle', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var dropdown = btn.closest('.government-header-search').querySelector('.government-search-dropdown');
          if (dropdown) {
            var isHidden = dropdown.hasAttribute('hidden');
            if (isHidden) {
              dropdown.removeAttribute('hidden');
              dropdown.classList.add('is-open');
              var input = dropdown.querySelector('input[type="search"]');
              if (input) input.focus();
            } else {
              dropdown.setAttribute('hidden', '');
              dropdown.classList.remove('is-open');
            }
          }
        });
      });

      // Close search when clicking outside
      document.addEventListener('click', function (e) {
        if (!e.target.closest('.government-header-search')) {
          var dropdown = document.querySelector('.government-search-dropdown:not([hidden])');
          if (dropdown) {
            dropdown.setAttribute('hidden', '');
            dropdown.classList.remove('is-open');
          }
        }
      });

      // Header scroll state
      var header = document.querySelector('header#header');
      if (header) {
        var onScroll = function () {
          if (window.scrollY > 40) {
            header.classList.add('is-scrolled');
          } else {
            header.classList.remove('is-scrolled');
          }
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
      }
    }
  };
})(Drupal, once);
