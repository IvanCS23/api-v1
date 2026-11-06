import Heading from '@/components/heading';

/**
 * Fase 6.31: la cuenta propia no se elimina desde aquí, igual que en la API.
 * Antes este componente borraba la cuenta, aunque fuera el último
 * administrador de la empresa. El endpoint responde con el mismo mensaje.
 */
export default function DeleteUser() {
    return (
        <div className="space-y-6" data-test="delete-user-disabled">
            <Heading
                variant="small"
                title="Eliminar cuenta"
                description="Tu cuenta no se puede eliminar desde aquí"
            />
            <div className="space-y-0.5 rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                <p>
                    Para dejar de usar el sistema, pide a un administrador de tu
                    empresa que desactive tu cuenta. Así se conserva el
                    historial y la empresa nunca se queda sin administrador.
                </p>
            </div>
        </div>
    );
}
