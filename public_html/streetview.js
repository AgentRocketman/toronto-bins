/**
 * Street View Module
 * Handles Google Street View display for property locations
 */

(function() {
  'use strict';

  let mapsApiKey = null;
  let mapsApiLoading = false;
  let mapsApiLoaded = false;

  // Fetch API key and load Google Maps
  async function initMapsApi() {
    return new Promise((resolve, reject) => {
      // If already loaded, resolve immediately
      if (mapsApiLoaded) {
        resolve();
        return;
      }

      // If currently loading, wait for it
      if (mapsApiLoading) {
        const checkInterval = setInterval(() => {
          if (mapsApiLoaded) {
            clearInterval(checkInterval);
            resolve();
          }
        }, 100);
        return;
      }

      mapsApiLoading = true;

      fetch('/api/maps-key.php')
        .then(res => res.json())
        .then(data => {
          mapsApiKey = data.key;
          
          // Load Google Maps API
          const script = document.createElement('script');
          script.src = `https://maps.googleapis.com/maps/api/js?key=${mapsApiKey}&libraries=geometry,places`;
          script.async = true;
          script.onload = () => {
            mapsApiLoaded = true;
            mapsApiLoading = false;
            console.log('Google Maps API loaded');
            resolve();
          };
          script.onerror = () => {
            mapsApiLoading = false;
            reject(new Error('Failed to load Google Maps API'));
          };
          document.head.appendChild(script);
        })
        .catch(err => {
          mapsApiLoading = false;
          console.error('Failed to fetch API key:', err);
          reject(err);
        });
    });
  }

  // Display Street View for an address
  async function displayStreetView(address, containerId) {
    try {
      // Ensure API is loaded
      await initMapsApi();
      
      const container = document.getElementById(containerId);
      if (!container) {
        console.error('Container not found:', containerId);
        return;
      }

      // Wait for Google Maps to be available
      let attempts = 0;
      while (!window.google || !window.google.maps) {
        await new Promise(r => setTimeout(r, 100));
        attempts++;
        if (attempts > 50) {
          throw new Error('Google Maps API failed to load');
        }
      }

      const geocoder = new google.maps.Geocoder();
      const streetViewService = new google.maps.StreetViewService();
      
      console.log('Geocoding address:', address);
      
      geocoder.geocode({ address: address }, (results, status) => {
        console.log('Geocode status:', status, 'Results:', results?.length);
        
        if (status === google.maps.GeocoderStatus.OK && results[0]) {
          const location = results[0].geometry.location;
          const formattedAddr = results[0].formatted_address;
          console.log('Location found:', location.lat(), location.lng());
          console.log('Formatted address from geocoder:', formattedAddr);
          
          // Check if Street View is available at this location
          streetViewService.getPanorama(
            { location: location, radius: 50 },
            (panoData, streetViewStatus) => {
              console.log('Street View status:', streetViewStatus);
              
              if (streetViewStatus === google.maps.StreetViewStatus.OK) {
                console.log('Creating Street View Panorama...');
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
                console.log('Street View displayed for:', address);
              } else {
                console.warn('Street View not available:', streetViewStatus);
                container.innerHTML = `<div style="padding: 20px; color: #999; text-align: center; font-size: 0.9rem;">
                  <p>Street View not available for this location</p>
                  <p style="font-size: 0.75rem; margin-top: 10px; color: #bbb;">Try major streets like "100 King St W" or "Yonge St, Toronto"</p>
                </div>`;
              }
            }
          );
        } else {
          console.warn('Geocode failed:', status, '- Address was:', address);
          container.innerHTML = `<div style="padding: 20px; color: #999; text-align: center; font-size: 0.9rem;">
            <p>Address not found by geocoder</p>
            <p style="font-size: 0.75rem; margin-top: 10px; color: #bbb;">Geocode status: ${status}<br>Check browser console for details</p>
          </div>`;
        }
      });
    } catch (err) {
      console.error('Street View error:', err);
      const container = document.getElementById(containerId);
      if (container) {
        container.innerHTML = '<p style="padding: 20px; color: #999; text-align: center;">Unable to load Street View</p>';
      }
    }
  }

  // Expose globally
  window.streetView = {
    init: initMapsApi,
    display: displayStreetView
  };

  console.log('Street View module loaded');

})();
