import * as Haptics from 'expo-haptics';

// Fire-and-forget wrappers: haptics must never block or throw.

export function tapFeedback(): void {
  void Haptics.selectionAsync().catch(() => {});
}

export function lightImpact(): void {
  void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => {});
}

export function successFeedback(): void {
  void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => {});
}

export function warningFeedback(): void {
  void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning).catch(() => {});
}
