/**
 * Firebase Cloud Messaging (FCM) registration for verified donors.
 *
 * Flow: browser permission → GET push_public_config → firebase.initializeApp →
 * register firebase-messaging-sw.js → messaging.getToken({ vapidKey }) → POST save_push_token.
 *
 * Exposes window.registerPushIfEligible(user) for login.js and profile.js.
 */
(function () {
    /**
     * @param {object} user Logged-in user object from API (must have role "donor" and id).
     * @returns {Promise<{ ok: boolean, message: string }>}
     */
    async function registerPushIfEligible(user) {
        if (!user || user.role !== "donor" || !user.id) {
            return { ok: false, message: "Push известията са само за донори." };
        }

        if (!("serviceWorker" in navigator) || !("Notification" in window) || typeof firebase === "undefined") {
            return { ok: false, message: "Браузърът не поддържа push известия." };
        }

        try {
            const permission = await Notification.requestPermission();
            if (permission !== "granted") {
                return { ok: false, message: "Нямаш разрешение за известия в браузъра." };
            }

            const configResponse = await fetch("../api/index.php?route=push_public_config");
            if (!configResponse.ok) {
                return { ok: false, message: "Липсва push конфигурация на сървъра." };
            }

            const configPayload = await configResponse.json();
            const firebaseConfig = configPayload?.data?.firebase || {};
            const vapidPublicKey = configPayload?.data?.vapid_public_key || "";
            const hasFirebaseConfig = firebaseConfig.api_key || firebaseConfig.apiKey;

            if (!hasFirebaseConfig || !vapidPublicKey) {
                return { ok: false, message: "Firebase конфигурацията е непълна." };
            }

            // API returns snake_case; Firebase JS SDK expects camelCase keys.
            const normalizedConfig = {
                apiKey: firebaseConfig.apiKey || firebaseConfig.api_key,
                authDomain: firebaseConfig.authDomain || firebaseConfig.auth_domain,
                projectId: firebaseConfig.projectId || firebaseConfig.project_id,
                storageBucket: firebaseConfig.storageBucket || firebaseConfig.storage_bucket,
                messagingSenderId: firebaseConfig.messagingSenderId || firebaseConfig.messaging_sender_id,
                appId: firebaseConfig.appId || firebaseConfig.app_id
            };

            if (!firebase.apps.length) {
                firebase.initializeApp(normalizedConfig);
            }

            const registration = await navigator.serviceWorker.register("/firebase-messaging-sw.js");
            const messaging = firebase.messaging();
            const token = await messaging.getToken({
                vapidKey: vapidPublicKey,
                serviceWorkerRegistration: registration
            });

            if (!token) {
                return { ok: false, message: "Не беше генериран push token." };
            }

            const saveResponse = await fetch("../api/index.php?route=save_push_token", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    user_id: user.id,
                    token,
                    enabled: true
                })
            });

            if (!saveResponse.ok) {
                return { ok: false, message: "Неуспешно записване на push token." };
            }

            return { ok: true, message: "Известията са активирани успешно." };
        } catch (_error) {
            // Notifications are best-effort and must not block login.
            return { ok: false, message: "Грешка при активиране на известия." };
        }
    }

    window.registerPushIfEligible = registerPushIfEligible;
})();
