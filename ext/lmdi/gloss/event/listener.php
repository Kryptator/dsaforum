//Glossar case sensitiv machen

++ suche ++

$variant = strtolower($variant);

++ Ersetze durch ++

//$variant = strtolower($variant);

++ suche ++

$end = ')\b/ui'; // PCRE - u = UTF-8 - i = case insensitive

++ ersetze durch ++

$end = ')\b/u'; // PCRE - u = UTF-8 - i = case insensitive
