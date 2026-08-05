/**
 * Carrito del POS que sobrevive a la navegacion (Fase 11).
 *
 * El problema: el carrito vivia unicamente en el `useState` de POSPage. Bastaba
 * con ir a Mesas a consultar una cuenta y volver para que la venta en curso se
 * evaporara — un cajero perdiendo un ticket a medio armar delante del cliente.
 *
 * Un store de modulo lo resuelve sin arrastrar el estado hasta un contexto
 * global: el modulo vive mientras viva la pestana, que es exactamente el
 * horizonte que queremos. NO se persiste en localStorage a proposito: un
 * carrito resucitado tras cerrar el navegador o al dia siguiente seria una
 * fuente de errores de cobro, no una comodidad.
 */
let cart = [];

/** @returns {Array} el carrito vigente (misma referencia si no ha cambiado) */
export function readCart() {
  return cart;
}

export function writeCart(next) {
  cart = next;
}

/** Se invoca al cerrar una venta: el ticket ya se cobro, el carrito muere. */
export function clearCart() {
  cart = [];
}
