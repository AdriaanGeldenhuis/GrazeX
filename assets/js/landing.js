/* GrazeX gateway — logo crossfade, 3D tilt and scroll parallax */
(function () {
  'use strict';
  const GX = window.GX;
  const stage = document.querySelector('[data-tilt]');
  if (!stage) return;

  // Crossfade Ranch ⇄ Feeds logos
  const slides = Array.from(stage.querySelectorAll('.logo-slide'));
  if (slides.length > 1 && !GX.reduceMotion) {
    let i = 0;
    setInterval(() => {
      if (document.hidden) return;
      slides[i].classList.remove('is-active');
      i = (i + 1) % slides.length;
      slides[i].classList.add('is-active');
    }, 4200);
  }

  if (GX.reduceMotion) return;

  // Pointer tilt (desktop) + gentle scroll parallax
  let rx = 3, ry = -6, ty = 0, raf = 0;
  const apply = () => { stage.style.transform = `perspective(1200px) rotateY(${ry}deg) rotateX(${rx}deg) translateY(${ty}px)`; };
  const schedule = () => { cancelAnimationFrame(raf); raf = requestAnimationFrame(apply); };

  if (window.matchMedia('(hover: hover)').matches) {
    const hero = stage.closest('.hero');
    hero.addEventListener('mousemove', (e) => {
      const r = hero.getBoundingClientRect();
      const px = (e.clientX - r.left) / r.width - 0.5;
      const py = (e.clientY - r.top) / r.height - 0.5;
      ry = -6 + px * 14;
      rx = 3 - py * 10;
      schedule();
    });
    hero.addEventListener('mouseleave', () => { rx = 3; ry = -6; schedule(); });
  }
  window.addEventListener('scroll', () => {
    const y = window.scrollY;
    if (y > window.innerHeight * 1.2) return;
    ty = y * 0.06;
    schedule();
  }, { passive: true });
})();
