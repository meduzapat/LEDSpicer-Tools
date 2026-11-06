-- Discovery for one set, run by MAME through -autoboot_script.
-- Writes key=value lines to $MAMECREDITS_OUT and exits MAME.
-- Stage 1: boot only.

local function report(values)
	local file = assert(io.open(os.getenv("MAMECREDITS_OUT"), "w"))
	for key, value in pairs(values) do
		file:write(key, "=", value, "\n")
	end
	file:close()
	manager.machine:exit()
end

report({status = "found", reason = "boot_only"})
