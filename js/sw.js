if (navigator.serviceWorker) {
    navigator.serviceWorker
        .register("./sw.php", {
            scope: "./",
        })
        .then(function (registration) {

            function handleMsg(event) {
                if(event.source instanceof ServiceWorker) {
                    const data = JSON.parse(event.data);
                    const src = event.source.state;

                    switch(data?.type) {
                        case "msg":
                            console.log(`Message from ${src} SW: `, ...data?.data);
                            break;

                        case "install_done":
                            event.source.postMessage("skip_waiting");
                            break;

                        case "activation_done": 
                            window.location.reload();
                            break;

                        default:
                            console.log("unknown message", data);
                            break;
                    }
                }    
            }

            registration.onupdatefound = function () {
                registration.installing.postMessage("Let's begin");
                registration.installing.onmessage = handleMsg;

            };

            if (registration.active) {
                registration.active.postMessage("Tune on in.");

            };

            if (registration.waiting) {
                registration.waiting.postMessage("I'm gonna make you wish that I stayed gone.");
                registration.waiting.postMessage("skip_waiting");

            };

            navigator.serviceWorker.onmessage = handleMsg;
        });
}
