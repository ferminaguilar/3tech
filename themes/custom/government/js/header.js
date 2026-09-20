/**
 * @file
 * Header utilities for Government layout (Mobile drawer toggle, Search toggle, Scroll states, Submenu toggles).
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.governmentHeader = {
    attach: function (context) {
      var nav = document.querySelector('.government-primary-nav, .usa-nav');
      var overlay = document.querySelector('.usa-overlay');
      var menuBtn = document.querySelector('.usa-menu-btn');

      // Function to open mobile navigation drawer
      function openMobileNav() {
        if (nav) {
          nav.classList.add('is-visible');
        }
        if (overlay) {
          overlay.classList.add('is-visible');
        }
        if (menuBtn) {
          menuBtn.setAttribute('aria-expanded', 'true');
        }
        document.body.classList.add('mobile-nav-active');
      }

      // Function to close mobile navigation drawer
      function closeMobileNav() {
        if (nav) {
          nav.classList.remove('is-visible');
        }
        if (overlay) {
          overlay.classList.remove('is-visible');
        }
        if (menuBtn) {
          menuBtn.setAttribute('aria-expanded', 'false');
        }
        document.body.classList.remove('mobile-nav-active');
      }

      // Mobile Menu Toggle Button
      once('government-mobile-menu-toggle', '.usa-menu-btn', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var isOpen = nav && nav.classList.contains('is-visible');
          if (isOpen) {
            closeMobileNav();
          } else {
            openMobileNav();
          }
        });
      });

      // Mobile Menu Close Button
      once('government-mobile-menu-close', '.usa-nav__close', context).forEach(function (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
          e.preventDefault();
          closeMobileNav();
        });
      });

      // Close when clicking overlay
      once('government-mobile-overlay', '.usa-overlay', context).forEach(function (ov) {
        ov.addEventListener('click', function () {
          closeMobileNav();
        });
      });

      // Close on Escape key press
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
          if (nav && nav.classList.contains('is-visible')) {
            closeMobileNav();
          }
        }
      });

      // Submenu Accordion buttons toggle
      once('government-accordion-button', '.usa-nav .usa-accordion__button', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var isExpanded = btn.getAttribute('aria-expanded') === 'true';
          var controlsId = btn.getAttribute('aria-controls');
          var submenu = controlsId ? document.getElementById(controlsId) : btn.nextElementSibling;

          // Toggle current
          if (isExpanded) {
            btn.setAttribute('aria-expanded', 'false');
            if (submenu) {
              submenu.setAttribute('hidden', '');
              submenu.classList.remove('is-open');
            }
          } else {
            // Close other sibling submenus in mobile view
            var parentList = btn.closest('.government-nav-primary, .usa-nav__primary');
            if (parentList) {
              parentList.querySelectorAll('.usa-accordion__button[aria-expanded="true"]').forEach(function (otherBtn) {
                if (otherBtn !== btn) {
                  otherBtn.setAttribute('aria-expanded', 'false');
                  var otherId = otherBtn.getAttribute('aria-controls');
                  var otherSub = otherId ? document.getElementById(otherId) : otherBtn.nextElementSibling;
                  if (otherSub) {
                    otherSub.setAttribute('hidden', '');
                    otherSub.classList.remove('is-open');
                  }
                }
              });
            }

            btn.setAttribute('aria-expanded', 'true');
            if (submenu) {
              submenu.removeAttribute('hidden');
              submenu.classList.add('is-open');
            }
          }
        });
      });

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

      // In-page navigation deduplication guard
      once('government-in-page-nav', '.usa-in-page-nav', context).forEach(function (navContainer) {
        var cleanDuplicates = function () {
          var navs = navContainer.querySelectorAll('.usa-in-page-nav__nav');
          if (navs.length > 1) {
            for (var i = 1; i < navs.length; i++) {
              navs[i].remove();
            }
          }
        };
        cleanDuplicates();
        var observer = new MutationObserver(cleanDuplicates);
        observer.observe(navContainer, { childList: true });
      });
    }
  };
})(Drupal, once);
