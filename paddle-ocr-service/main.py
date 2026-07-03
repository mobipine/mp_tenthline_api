import os
import tempfile
from fastapi import FastAPI, File, UploadFile

app = FastAPI(title="TenthLine OCR Service")


def env_flag(name: str, default: str = "false") -> bool:
    return os.getenv(name, default).lower() in ("true", "1", "yes")


# Engine selection:
#   rapidocr (default) — PaddleOCR models running on onnxruntime; ~6x faster
#                        per page on CPU than paddlepaddle and far lighter on
#                        memory. Best choice for CPU deployments.
#   paddle             — native PaddleOCR pipeline; use when you need a
#                        specific Paddle model (DET_MODEL_NAME/REC_MODEL_NAME)
#                        or GPU inference (USE_GPU=true).
ENGINE = os.getenv("OCR_ENGINE", "rapidocr").lower()

det_model_dir = os.getenv("DET_MODEL_DIR", None)
rec_model_dir = os.getenv("REC_MODEL_DIR", None)
use_gpu = env_flag("USE_GPU")
ocr = None


def build_paddle():
    from paddleocr import PaddleOCR

    options = {
        "lang": "en",
        "use_doc_orientation_classify": env_flag("USE_DOC_ORIENTATION_CLASSIFY"),
        "use_doc_unwarping": env_flag("USE_DOC_UNWARPING"),
        "use_textline_orientation": env_flag("USE_TEXTLINE_ORIENTATION"),
        "text_det_limit_side_len": int(os.getenv("TEXT_DET_LIMIT_SIDE_LEN", "1280")),
        "text_detection_model_name": os.getenv("DET_MODEL_NAME", "PP-OCRv5_mobile_det"),
        "text_recognition_model_name": os.getenv("REC_MODEL_NAME", "PP-OCRv5_mobile_rec"),
        "text_recognition_batch_size": int(os.getenv("REC_BATCH_SIZE", "16")),
        "cpu_threads": int(os.getenv("CPU_THREADS", str(os.cpu_count() or 4))),
    }

    if det_model_dir:
        options["text_detection_model_dir"] = det_model_dir

    if rec_model_dir:
        options["text_recognition_model_dir"] = rec_model_dir

    if use_gpu:
        options["device"] = "gpu:0"

    return PaddleOCR(**options)


def build_rapidocr():
    from rapidocr_onnxruntime import RapidOCR

    # Keep the engine's own recognition-score filter near zero: dropping a real
    # line shifts every tenthline number below it, so low-confidence filtering
    # is left to the Laravel side (min_confidence + the line scorer).
    return RapidOCR(text_score=float(os.getenv("TEXT_SCORE", "0.05")))


@app.on_event("startup")
def startup():
    global ocr
    ocr = build_paddle() if ENGINE == "paddle" else build_rapidocr()


@app.get("/health")
def health():
    return {"status": "ok", "ready": ocr is not None, "engine": ENGINE}


@app.post("/ocr/page")
async def ocr_page(file: UploadFile = File(...), min_confidence: float = 0.0):
    if ocr is None:
        return {"success": False, "error": "OCR model is not ready yet."}

    with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
        tmp.write(await file.read())
        tmp_path = tmp.name

    try:
        if ENGINE == "paddle":
            result = ocr.predict(tmp_path)
            lines = extract_paddle_lines(result, min_confidence)
        else:
            result, _ = ocr(tmp_path)
            lines = extract_rapidocr_lines(result, min_confidence)
        return {"success": True, "lines": lines}
    except Exception as e:
        return {"success": False, "error": str(e)}
    finally:
        if os.path.exists(tmp_path):
            os.unlink(tmp_path)


def extract_rapidocr_lines(result, min_confidence: float):
    lines = []

    for item in result or []:
        bbox, text, conf = item[0], item[1], float(item[2])
        if conf < min_confidence:
            continue

        bbox_list = bbox.tolist() if hasattr(bbox, "tolist") else bbox
        lines.append({
            "bbox": bbox_list,
            "text": str(text),
            "confidence": round(conf, 4),
        })

    return lines


def extract_paddle_lines(result, min_confidence: float):
    lines = []

    if not result:
        return lines

    first = result[0]

    # PaddleOCR 3.x / PaddleX-style result object
    if hasattr(first, "keys") and "dt_polys" in first and "rec_texts" in first:
        polys = first.get("dt_polys") or []
        texts = first.get("rec_texts") or []
        scores = first.get("rec_scores") or []

        for idx, text in enumerate(texts):
            conf = float(scores[idx]) if idx < len(scores) else 0.0
            if conf < min_confidence:
                continue

            bbox = polys[idx] if idx < len(polys) else None
            if bbox is None:
                continue

            bbox_list = bbox.tolist() if hasattr(bbox, "tolist") else bbox
            lines.append({
                "bbox": bbox_list,
                "text": str(text),
                "confidence": round(conf, 4),
            })

        return lines

    # Legacy PaddleOCR list format
    if result and result[0]:
        for line in result[0]:
            bbox = line[0]
            text, conf = line[1]
            conf = float(conf)
            if conf < min_confidence:
                continue
            lines.append({
                "bbox": bbox,
                "text": text,
                "confidence": round(conf, 4),
            })

    return lines
