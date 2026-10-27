import type { ErrorRequestHandler } from "express";

export const errorHandler: ErrorRequestHandler = (error, _req, res, _next) => {
  if (
    error &&
    typeof error === "object" &&
    "issues" in error &&
    Array.isArray((error as { issues?: unknown }).issues)
  ) {
    res
      .status(400)
      .json({
        error: {
          code: "VALIDATION_ERROR",
          message: "Request validation failed",
          details: (error as { issues: unknown }).issues,
        },
      });
    return;
  }
  console.error(
    "Unhandled request error",
    error instanceof Error ? error.message : "Unknown error",
  );
  if (
    error &&
    typeof error === "object" &&
    "status" in error &&
    "code" in error
  ) {
    const typed = error as { status: number; code: string; message: string };
    res
      .status(typed.status)
      .json({ error: { code: typed.code, message: typed.message } });
    return;
  }
  res
    .status(500)
    .json({
      error: {
        code: "INTERNAL_ERROR",
        message: "An unexpected error occurred",
      },
    });
};
