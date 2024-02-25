if(window.navigator.serviceWorker) {

    window.navigator.serviceWorker
        .register("./sw.php", { scope: "./" })
        .then( (req) => {

            if(req.waiting) {
//                window.location.href=window.location.href
            }

        } )
    
}
