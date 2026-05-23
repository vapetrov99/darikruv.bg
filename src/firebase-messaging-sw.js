/**
 * Firebase Messaging service worker (runs in a separate worker context, not the page).
 *
 * - "push": decode FCM payload and call showNotification (title/body from notification payload, request id from data).
 * - "notificationclick": open the request detail page when the user taps the notification.
 */

self.addEventListener("push", (event) => {
    if (!event.data) {
        return;
    }

    let payload = {};
    try {
        payload = event.data.json();
    } catch (_error) {
        payload = { notification: { title: "DariKruv", body: event.data.text() } };
    }

    const title = payload?.notification?.title || "Нова заявка за кръв";
    const body = payload?.notification?.body || "Провери платформата за детайли.";
    const requestId = payload?.data?.request_id || null;

    event.waitUntil(
        self.registration.showNotification(title, {
            body,
            icon: "/assets/images/sofia1.png",
            data: {
                requestId
            }
        })
    );
});

self.addEventListener("notificationclick", (event) => {
    event.notification.close();
    const requestId = event.notification?.data?.requestId;
    const targetUrl = requestId ? `/html/request-details.html?id=${requestId}` : "/html/request.html";

    event.waitUntil(clients.openWindow(targetUrl));
});
