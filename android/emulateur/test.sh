#!/usr/bin/env bash
# Test de l'application SOTRAMA dans l'émulateur Android (lancé par .github/workflows/emulateur.yml).
# Usage : android/emulateur/test.sh chemin/vers/sotrama.apk dossier-resultats
# Installe l'APK, ouvre l'application, prend des captures d'écran et une vidéo,
# enregistre le journal (logcat) et échoue si l'application plante.
set -uo pipefail

APK=${1:?chemin de l APK}
OUT=${2:-resultats}
PKG=com.sotrama.app
mkdir -p "$OUT"

capture() { adb exec-out screencap -p > "$OUT/$1.png" && echo "Capture : $OUT/$1.png"; }

adb wait-for-device
adb shell getprop ro.build.version.release | sed 's/^/Android /'
adb shell settings put global window_animation_scale 0
adb shell settings put global transition_animation_scale 0
adb shell settings put global animator_duration_scale 0
# Évite l'écran d'accueil de Chrome au premier lancement (Chrome ne lit ce fichier
# que s'il est déclaré comme application de débogage).
adb shell 'echo "_ --disable-fre --no-default-browser-check --no-first-run" > /data/local/tmp/chrome-command-line'
adb shell am set-debug-app --persistent com.android.chrome
adb shell am force-stop com.android.chrome

echo "== Installation"
adb install -r -g "$APK" || { echo "::error::Installation de l'APK impossible"; exit 1; }
adb shell dumpsys package "$PKG" | grep -E "versionName|versionCode|targetSdk" | head -3

capture 00-accueil-android
adb logcat -c

echo "== Lancement"
adb shell screenrecord --time-limit 60 /sdcard/sotrama.mp4 &
REC=$!
adb shell monkey -p "$PKG" -c android.intent.category.LAUNCHER 1 >/dev/null
for t in 5 15 30 45; do
  sleep $(( t - ${prec:-0} )); prec=$t
  capture "$(printf '%02d' "$t")s-apres-lancement"
done
wait $REC 2>/dev/null
adb pull /sdcard/sotrama.mp4 "$OUT/sotrama.mp4" >/dev/null && echo "Vidéo : $OUT/sotrama.mp4"

echo "== État"
adb shell dumpsys activity activities | grep -E "mResumedActivity|topResumedActivity" | head -2 | tee "$OUT/activite.txt"
adb shell uiautomator dump /sdcard/ecran.xml >/dev/null 2>&1 && adb pull /sdcard/ecran.xml "$OUT/ecran.xml" >/dev/null
adb logcat -d > "$OUT/logcat.txt"
grep -E "Digital Asset|TWA|TrustedWebActivity|LauncherActivity" "$OUT/logcat.txt" | tail -20

if grep -q "FirstRunActivity" "$OUT/activite.txt"; then
  echo "::warning::Chrome est resté sur son écran d'accueil : le site n'a pas été affiché"
fi

# Échec si l'application a planté.
if grep -A20 "FATAL EXCEPTION" "$OUT/logcat.txt" | grep -q "$PKG"; then
  echo "::error::L'application SOTRAMA a planté (voir logcat.txt)"
  grep -A30 "FATAL EXCEPTION" "$OUT/logcat.txt"
  exit 1
fi
if grep -q "ANR in $PKG" "$OUT/logcat.txt"; then
  echo "::error::L'application SOTRAMA ne répond plus (ANR)"
  exit 1
fi
echo "Aucun plantage détecté. Captures, vidéo et journal dans $OUT/"
