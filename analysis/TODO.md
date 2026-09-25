# TODO

## Worker video

- Fare in modo che `openbook:process-videos` osservi il segnale Laravel di
  `queue:restart` e termini ordinatamente dopo il lavoro corrente, lasciando
  che systemd/Supervisor lo riavvii con il codice aggiornato dopo un deploy.
