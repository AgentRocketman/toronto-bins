// Firebase Configuration
// Replace config values with your Firebase project credentials

const firebaseConfig = {
  apiKey: "YOUR_API_KEY",
  authDomain: "your-project.firebaseapp.com",
  projectId: "your-project-id",
  storageBucket: "your-project.appspot.com",
  messagingSenderId: "YOUR_SENDER_ID",
  appId: "YOUR_APP_ID"
};

// Initialize Firebase
firebase.initializeApp(firebaseConfig);

// Get Firestore reference
const db = firebase.firestore();

// Cloud Functions reference
const processPayment = firebase.functions().httpsCallable('processPayment');

// Export for use in other scripts
window.firebaseDB = db;
window.processPayment = processPayment;
