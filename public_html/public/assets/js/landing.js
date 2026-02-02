const slider = document.getElementById('slider');
    const sliderContainer = document.querySelector('.slider-container');
    const arrowButtons = document.querySelectorAll('.slider-container .arrow');
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let autoPlayInterval;

    function scrollSlider(direction) {
        const slideWidth = slider.clientWidth;
        const currentScroll = slider.scrollLeft;
        const maxScroll = slider.scrollWidth - slideWidth;
        
        if (direction === 1 && currentScroll >= maxScroll - 10) {
            slider.scrollTo({ left: 0, behavior: 'smooth' });
        } else if (direction === -1 && currentScroll <= 10) {
            slider.scrollTo({ left: maxScroll, behavior: 'smooth' });
        } else {
            slider.scrollBy({ left: direction * slideWidth, behavior: 'smooth' });
        }
    }

    function manualScroll(direction) {
        scrollSlider(direction);
        resetAutoPlay();
    }

    function startAutoPlay() {
        if (prefersReducedMotion.matches || autoPlayInterval) {
            return;
        }
        autoPlayInterval = setInterval(() => {
            scrollSlider(1);
        }, 5000);
    }

    function stopAutoPlay() {
        clearInterval(autoPlayInterval);
        autoPlayInterval = null;
    }

    function resetAutoPlay() {
        if (prefersReducedMotion.matches) {
            stopAutoPlay();
            return;
        }
        stopAutoPlay();
        startAutoPlay();
    }

    function pauseAutoPlay() {
        stopAutoPlay();
    }

    function resumeAutoPlay() {
        startAutoPlay();
    }

    sliderContainer.addEventListener('mouseenter', pauseAutoPlay);
    sliderContainer.addEventListener('mouseleave', resumeAutoPlay);
    sliderContainer.addEventListener('focusin', pauseAutoPlay);
    sliderContainer.addEventListener('focusout', resumeAutoPlay);
    sliderContainer.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            manualScroll(-1);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            manualScroll(1);
        }
    });

    arrowButtons.forEach((button) => {
        button.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                const direction = button.classList.contains('arrow-left') ? -1 : 1;
                manualScroll(direction);
            }
        });
    });

    prefersReducedMotion.addEventListener('change', () => {
        if (prefersReducedMotion.matches) {
            stopAutoPlay();
        } else {
            startAutoPlay();
        }
    });

    startAutoPlay();

    const testimonialNames = document.querySelectorAll('.testimonial-name');
    testimonialNames.forEach((nameElement) => {
        const displayName = nameElement.textContent.trim();
        const dataHandle = nameElement.dataset.instagram?.trim();
        let handle = dataHandle;

        if (!handle && displayName.startsWith('@')) {
            handle = displayName.slice(1);
        }

        if (!handle) {
            return;
        }

        handle = handle.replace(/^@/, '');

        const link = document.createElement('a');
        link.className = 'testimonial-instagram';
        link.href = `https://instagram.com/${handle}`;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';

        const icon = document.createElement('i');
        icon.className = 'ph ph-instagram-logo';
        icon.setAttribute('aria-hidden', 'true');

        const nameText = document.createElement('span');
        nameText.className = 'testimonial-name-text';
        nameText.textContent = displayName;

        link.append(icon, nameText);
        nameElement.replaceWith(link);
    });
