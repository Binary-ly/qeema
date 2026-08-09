# Qeema ML Service

Stateless FastAPI service providing the three ML capabilities of the Qeema
open affordability index:

1. **Product matching** — resolve free-text price observations (Arabic, Latin
   or mixed script) to canonical basket items via hybrid lexical + semantic
   retrieval.
2. **Anomaly scoring** — flag erroneous and manipulated submissions.
3. **Nowcasting** — impute missing prices with calibrated uncertainty
   intervals.

All models run locally from open weights. No external or paid inference API is
used anywhere in the runtime path.

See `../docs/` for architecture and model cards. Licensed Apache-2.0.
