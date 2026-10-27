import type { NextFunction, Request, Response } from "express";
import jwt, { type JwtPayload } from "jsonwebtoken";
import { env } from "../config/env.js";

export interface AdminRequest extends Request {
  actor?: { id: string; role: "admin" };
}

export function requireAdmin(
  req: AdminRequest,
  res: Response,
  next: NextFunction,
): void {
  const authorization = req.header("authorization");
  const token = authorization?.startsWith("Bearer ")
    ? authorization.slice(7)
    : undefined;
  if (!token) {
    res
      .status(401)
      .json({
        error: { code: "UNAUTHENTICATED", message: "Bearer token required" },
      });
    return;
  }

  try {
    const claims = jwt.verify(token, env.ADMIN_JWT_SECRET, {
      algorithms: ["HS256"],
      issuer: env.ADMIN_JWT_ISSUER,
      audience: env.ADMIN_JWT_AUDIENCE,
    }) as JwtPayload;
    if (typeof claims.sub !== "string" || claims.role !== "admin") {
      res
        .status(403)
        .json({
          error: { code: "FORBIDDEN", message: "Admin access required" },
        });
      return;
    }
    req.actor = { id: claims.sub, role: "admin" };
    next();
  } catch {
    res
      .status(401)
      .json({
        error: { code: "UNAUTHENTICATED", message: "Invalid or expired token" },
      });
  }
}
