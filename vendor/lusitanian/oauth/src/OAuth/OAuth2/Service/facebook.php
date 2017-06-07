// Ein Fix um den FB Login mit Boardmitteln zu ermöglichen.

++ suche ++

        // Facebook gives us a query string ... Oh wait. JSON is too simple, understand ?
        parse_str($responseBody, $data);
        
++ ersetze durch ++

        $data = @json_decode($responseBody, true);
        // Facebook gives us a query string on old api (v2.0)
        if (!$data) {
            parse_str($responseBody, $data);
        }
