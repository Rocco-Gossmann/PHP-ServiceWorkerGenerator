if (navigator.serviceWorker) {
    navigator.serviceWorker
        .register("./sw.php", {
            scope: "./",
        })
        .then(function (registration) {
            let sw = null;

            if (registration.active) {
                console.log("active worker");

                registration.active.postMessage("Let's begin");
                sw = registration.active;
            }

            if (registration.waiting) {
                console.log("waiting worker");

                registration.waiting.postMessage("skip_waiting");
                sw = registration.waiting;
            } else if (registration.installing) {
                console.log("installing worker");

                registration.installing.postMessage("Welcome Home");
            }

            navigator.serviceWorker.onmessage = function(data) {
                console.log("got message: ", data.data)
            }
        });
}
