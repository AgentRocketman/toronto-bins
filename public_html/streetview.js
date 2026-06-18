/**
 * Street View Module
 * Handles Google Street View display for property locations
 */

(function() {
  'use strict';

  let mapsApiKey = null;
  let streetViewInitialized = false;

  // Fetch API key from backend
  async function initMapsApi() {
    if (streetViewInitialized) return;
    
    try {
      const response = await fetch('/api/maps-key.php');
      const data = await response.json();
      mapsApiKey = data.key;
      
      // Load Google Maps API
      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${mapsApiKey}`;
      script.async = true;
      script.defer = true;
      document.head.appendChild(script);
      
      streetViewInitialized = true;
    } catch (err) {
      console.warn('Failed to load Street View:', err);
    }
  }

  // Display Street View for an address
  function displayStreetView(address, containerId) {
    if (!streetViewInitialized) {
      console.warn('Street View not initialized');
      return;
    }

    const geocoder = new google.maps.Geocoder();
    
    geocoder.geocode({ address: address }, (results, status) => {
      if (status === google.maps.GeocoderStatus.OK && results[0]) {
        const location = results[0].geometry.location;
        const container = document.getElementById(containerId);
        
        if (!container) return;

        // Create Street View
        const streetView = new google.maps.StreetViewPanorama(container, {
          position: location,
          pov: { heading: 0, pitch: 0 },
          zoom: 1,
          streetViewControl: true,
          panControl: true,
          zoomControl: true,
          fullscreenControl: true
        });

        // Add a marker for the property
        const map = new google.maps.Map(document.createElement('div'), {
          center: location,
          zoom: 15
        });

        new google.maps.Marker({
          position: location,
          map: map,
          title: address
        });
      } else {
        console.warn('Street View not available for this address');
        const container = document.getElementById(containerId);
        if (container) {
          container.innerHTML = '<p style="padding: 20px; color: #666;">Street View not available for this address</p>';
        }
      }
    });
  }

  // Expose globally
  window.streetView = {
    init: initMapsApi,
    display: displayStreetView
  };

})();
